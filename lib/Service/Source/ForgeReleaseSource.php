<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

use Exception;
use OCA\Versioniq\Service\Advisory\AdvisorySourceInterface;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatResolver;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Lists releases for an `owner/repo` on a git forge (GitHub, Codeberg/Forgejo)
 * and resolves a release into a downloadable archive URL. The forge to talk to
 * is read from the binding's `forge` field via {@see ForgeRegistry}; the only
 * per-forge differences are the API base URL and the auth-header scheme, both
 * carried by {@see Forge}. Release JSON is identical across forges.
 *
 * Falls back to unauthenticated requests when no applicable PAT exists; uses a
 * PAT (resolved via `PatResolver`, scoped to the binding's forge) when one
 * matches and is visible to the current admin.
 *
 * @psalm-api
 */
class ForgeReleaseSource implements SourceInterface, AdvisorySourceInterface {
	private const USER_AGENT = 'Nextcloud-Versioniq';

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private PatResolver $patResolver,
		private PatManager $patManager,
		private IUserSession $userSession,
		private ForgeRegistry $forgeRegistry,
		private IConfig $config,
	) {
	}

	/**
	 * Whether outbound forge fetches may target a local/private address.
	 *
	 * Defers to Nextcloud's own `allow_local_remote_servers` system switch
	 * (default `false`, so a stock instance keeps blocking local addresses)
	 * rather than hardcoding the answer — an operator who points a forge at a
	 * self-hosted deployment on a private network flips that one switch, exactly
	 * as they would for any other server-side fetch.
	 */
	private function allowLocalAddress(): bool {
		return $this->config->getSystemValueBool('allow_local_remote_servers', false);
	}

	public function getKind(): string {
		return SourceBinding::KIND_GITHUB_RELEASE;
	}

	public function getInstallerKind(): string {
		return self::INSTALLER_EXTERNAL;
	}

	/**
	 * Lists release tags (PAT-authenticated when matched), deduped newest-first; see "GitHub releases as a source"
	 * and "Version listings carry release notes".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/changelog-visibility/spec.md
	 */
	public function listVersions(string $appId, SourceBinding $binding): array {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return ['versions' => [], 'error' => 'Source binding is not a forge-release binding.'];
		}
		$forge = $this->forgeRegistry->get($binding->getForge());

		$result = $this->fetchReleases($forge, $ownerRepo);
		if ($result['ok'] === false) {
			$error = $result['error'] ?? $this->forgeName($forge) . ' API request failed.';

			return ['versions' => [], 'error' => $error];
		}

		$releases = $result['releases'] ?? [];

		$versions = [];
		/** @var mixed $release */
		foreach ($releases as $release) {
			if (!is_array($release)) {
				continue;
			}
			$tag = $release['tag_name'] ?? null;
			if (!is_string($tag) || $tag === '') {
				continue;
			}
			$versions[] = [
				'version' => $this->normalizeVersion($tag),
				'changelog' => $this->extractChangelog($release),
			];
		}

		return ['versions' => $this->dedupeAndSort($versions), 'error' => null];
	}

	/**
	 * Resolves a release into a download payload, enforcing unambiguous asset selection; see "External install integrity checks".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return null;
		}
		$forge = $this->forgeRegistry->get($binding->getForge());

		$result = $this->fetchReleases($forge, $ownerRepo);
		if ($result['ok'] === false) {
			return null;
		}

		$releases = $result['releases'] ?? [];

		$assetPattern = $binding->getAssetPattern();
		/** @var mixed $release */
		foreach ($releases as $release) {
			if (!is_array($release)) {
				continue;
			}
			$tag = $release['tag_name'] ?? null;
			if (!is_string($tag)) {
				continue;
			}
			if ($this->normalizeVersion($tag) !== $version && $tag !== $version) {
				continue;
			}

			return $this->buildReleasePayload($release, $assetPattern);
		}

		return null;
	}

	/**
	 * Lists published security advisories for the bound `owner/repo` from the
	 * forge's `/security-advisories` endpoint (GitHub GHSA / Forgejo), reusing
	 * the same PAT-authenticated fetch path as release listing — no new HTTP
	 * client, no new secret. Each advisory is normalized to the correlation
	 * record shape; transient errors return an empty list with a populated
	 * `error` string rather than throwing.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @return array{advisories: list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}>, error: ?string}
	 */
	public function listAdvisories(string $appId, SourceBinding $binding): array {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return ['advisories' => [], 'error' => 'Source binding is not a forge-release binding.'];
		}
		$forge = $this->forgeRegistry->get($binding->getForge());

		$result = $this->fetchAdvisories($forge, $ownerRepo);
		if ($result['ok'] === false) {
			return ['advisories' => [], 'error' => $result['error'] ?? $this->forgeName($forge) . ' advisory request failed.'];
		}

		$advisories = [];
		// `releases` is present on every ok:true branch of performFetch, but the
		// union narrows to optional keys through useToken's generic, so Psalm
		// sees `releases?:` and a possible null. Reading it defensively is
		// cheaper than a baseline entry and is correct either way: a body that
		// arrives without the key yields no advisories rather than a
		// foreach-over-null warning.
		$releases = ($result['releases'] ?? []);
		/** @var mixed $entry */
		foreach ($releases as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$normalized = $this->normalizeAdvisory($entry);
			if ($normalized !== null) {
				$advisories[] = $normalized;
			}
		}

		return ['advisories' => $advisories, 'error' => null];
	}

	/**
	 * Normalizes a forge (GHSA-shaped) advisory record into the correlation
	 * record. Reads the affected version range and first-patched version from
	 * the first vulnerability entry (forge advisories list one package).
	 *
	 * @param array<array-key, mixed> $entry
	 * @return array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}|null
	 */
	private function normalizeAdvisory(array $entry): ?array {
		/** @var mixed $id */
		$id = $entry['ghsa_id'] ?? $entry['id'] ?? $entry['cve_id'] ?? null;
		if (!is_string($id) || $id === '') {
			return null;
		}
		/** @var mixed $severity */
		$severity = $entry['severity'] ?? 'medium';
		/** @var mixed $summary */
		$summary = $entry['summary'] ?? ($entry['title'] ?? '');

		$affected = [];
		$firstPatched = null;
		/** @var mixed $vulnerabilities */
		$vulnerabilities = $entry['vulnerabilities'] ?? [];
		if (is_array($vulnerabilities) && isset($vulnerabilities[0]) && is_array($vulnerabilities[0])) {
			$vuln = $vulnerabilities[0];
			/** @var mixed $range */
			$range = $vuln['vulnerable_version_range'] ?? null;
			if (is_string($range) && trim($range) !== '') {
				foreach (explode(',', $range) as $clause) {
					if (trim($clause) !== '') {
						$affected[] = trim($clause);
					}
				}
			}
			/** @var mixed $patched */
			$patched = $vuln['first_patched_version'] ?? null;
			if (is_array($patched)) {
				$patched = $patched['identifier'] ?? null;
			}
			if (is_string($patched) && $patched !== '') {
				$firstPatched = $this->normalizeVersion($patched);
			}
		}

		return [
			'id' => $id,
			'severity' => is_string($severity) ? strtolower($severity) : 'medium',
			'summary' => is_string($summary) ? $summary : '',
			'affected' => $affected,
			'firstPatchedVersion' => $firstPatched,
		];
	}

	/**
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function fetchAdvisories(Forge $forge, string $ownerRepo): array {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID();
		$pat = $uid !== null ? $this->patResolver->findFor($forge->id, $ownerRepo, $uid) : null;

		$endpoint = $forge->advisoriesEndpoint($ownerRepo);

		if ($pat === null) {
			return $this->performFetch($forge, $endpoint, null);
		}

		/** @var array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string} */
		return $this->patManager->useToken($pat, fn (string $token): array => $this->performFetch($forge, $endpoint, $token));
	}

	/**
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function fetchReleases(Forge $forge, string $ownerRepo): array {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID();
		$pat = $uid !== null ? $this->patResolver->findFor($forge->id, $ownerRepo, $uid) : null;

		$endpoint = $forge->releasesEndpoint($ownerRepo);

		if ($pat === null) {
			return $this->performFetch($forge, $endpoint, null);
		}

		/** @var array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string} */
		return $this->patManager->useToken($pat, fn (string $token): array => $this->performFetch($forge, $endpoint, $token));
	}

	/**
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function performFetch(Forge $forge, string $endpoint, ?string $token): array {
		$headers = [
			'Accept' => 'application/json',
			'User-Agent' => self::USER_AGENT,
		];
		// GitHub-specific content negotiation; harmless to omit on Forgejo.
		if ($forge->id === ForgeRegistry::FORGE_GITHUB) {
			$headers['Accept'] = 'application/vnd.github+json';
			$headers['X-GitHub-Api-Version'] = '2022-11-28';
		}
		if ($token !== null) {
			$headers['Authorization'] = $forge->authHeaderValue($token);
		}

		try {
			$response = $this->clientService->newClient()->get($endpoint, [
				'headers' => $headers,
				'timeout' => 30,
				// IClient throws on 4xx by default; we want to inspect the
				// status code ourselves to produce useful errors.
				'http_errors' => false,
				// SSRF defence-in-depth: local addresses are blocked unless the
				// operator has enabled Nextcloud's allow_local_remote_servers.
				'nextcloud' => ['allow_local_address' => $this->allowLocalAddress()],
			]);
		} catch (Exception $error) {
			$this->logger->warning('ForgeReleaseSource: fetch failed', [
				'forge' => $forge->id,
				'endpoint' => $endpoint,
				'message' => $error->getMessage(),
			]);

			return ['ok' => false, 'error' => $this->humanizeError($forge, $error->getMessage())];
		}

		$status = $response->getStatusCode();
		$name = $this->forgeName($forge);
		if ($status === 404) {
			return ['ok' => false, 'error' => $name . ' repository not found.'];
		}
		if ($status === 401) {
			return ['ok' => false, 'error' => $name . ' authentication failed — the configured PAT may be revoked or expired.'];
		}
		if ($status === 403) {
			return ['ok' => false, 'error' => $name . ' rate limit exceeded — try again later, or configure a PAT.'];
		}
		if ($status !== 200) {
			return ['ok' => false, 'error' => sprintf('%s API returned HTTP %d.', $name, $status)];
		}

		try {
			$decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ['ok' => false, 'error' => $name . ' API returned malformed JSON.'];
		}

		if (!is_array($decoded) || !array_is_list($decoded)) {
			return ['ok' => false, 'error' => $name . ' API returned an unexpected payload shape.'];
		}

		return ['ok' => true, 'releases' => $decoded];
	}

	/**
	 * @param array<array-key, mixed> $release
	 * @return array<string, mixed>|null
	 */
	private function buildReleasePayload(array $release, string $assetPattern): ?array {
		/** @var mixed $assets */
		$assets = $release['assets'] ?? [];
		if (!is_array($assets) || !array_is_list($assets)) {
			return null;
		}

		$matchingAssets = [];
		$shaUrl = null;
		/** @var mixed $asset */
		foreach ($assets as $asset) {
			if (!is_array($asset)) {
				continue;
			}
			/** @var mixed $name */
			$name = $asset['name'] ?? '';
			/** @var mixed $url */
			$url = $asset['browser_download_url'] ?? '';
			if (!is_string($name) || !is_string($url) || $name === '' || $url === '') {
				continue;
			}
			if (fnmatch($assetPattern, $name, FNM_NOESCAPE)) {
				$matchingAssets[] = ['name' => $name, 'url' => $url];
			}
			// Capture .sha256 sibling if present anywhere in the release.
			if (str_ends_with($name, '.sha256')) {
				$shaUrl = $url;
			}
		}

		if (count($matchingAssets) === 0) {
			return [
				'error' => sprintf('No release asset matches pattern "%s".', $assetPattern),
			];
		}

		if (count($matchingAssets) > 1) {
			$names = array_map(static fn (array $a): string => $a['name'], $matchingAssets);

			return [
				'error' => sprintf(
					'Multiple matching assets for pattern "%s" (%s) — set explicit assetPattern.',
					$assetPattern,
					implode(', ', $names)
				),
			];
		}

		/** @var mixed $tag */
		$tag = $release['tag_name'] ?? '';

		return [
			'kind' => 'github-release',
			'download' => $matchingAssets[0]['url'],
			'assetName' => $matchingAssets[0]['name'],
			'sha256Url' => $shaUrl,
			'version' => is_string($tag) ? $this->normalizeVersion($tag) : '',
			'tagName' => is_string($tag) ? $tag : '',
		];
	}

	private function normalizeVersion(string $tag): string {
		if (str_starts_with($tag, 'v') || str_starts_with($tag, 'V')) {
			return substr($tag, 1);
		}

		return $tag;
	}

	/**
	 * @param list<array{version: string, changelog: ?string}> $versions
	 * @return list<array{version: string, changelog: ?string}>
	 */
	private function dedupeAndSort(array $versions): array {
		$seenAt = [];
		$unique = [];
		foreach ($versions as $entry) {
			if (!isset($seenAt[$entry['version']])) {
				$seenAt[$entry['version']] = count($unique);
				$unique[] = $entry;
				continue;
			}
			// Duplicate tag (e.g. re-listed across pagination); keep the
			// first-seen entry but backfill its changelog if it was empty.
			$index = $seenAt[$entry['version']];
			if ($unique[$index]['changelog'] === null && $entry['changelog'] !== null) {
				$unique[$index]['changelog'] = $entry['changelog'];
			}
		}

		usort(
			$unique,
			/**
			 * @param array{version: string, changelog: ?string} $a
			 * @param array{version: string, changelog: ?string} $b
			 */
			static fn (array $a, array $b): int => version_compare($b['version'], $a['version'])
		);

		return $unique;
	}

	/**
	 * Maps a forge release's `body` (release notes markdown) into the
	 * changelog field. Fail-soft: any mapping failure is caught and yields
	 * `null` so a single malformed release never fails the whole listing.
	 *
	 * @spec openspec/specs/changelog-visibility/spec.md
	 * @param array<array-key, mixed> $release
	 */
	private function extractChangelog(array $release): ?string {
		try {
			return $this->rawChangelogFrom($release);
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * @param array<array-key, mixed> $release
	 */
	private function rawChangelogFrom(array $release): ?string {
		/** @var mixed $body */
		$body = $release['body'] ?? null;
		if ($body === null) {
			return null;
		}
		if (!is_string($body)) {
			throw new UnexpectedValueException('Release body is not a string.');
		}

		return trim($body) === '' ? null : $body;
	}

	private function humanizeError(Forge $forge, string $raw): string {
		$name = $this->forgeName($forge);
		if (stripos($raw, 'rate limit') !== false) {
			return $name . ' rate limit exceeded — try again later, or configure a PAT.';
		}
		$host = parse_url($forge->apiBaseUrl, PHP_URL_HOST);
		if (is_string($host) && stripos($raw, 'could not resolve host') !== false) {
			return sprintf('Could not reach %s — check network connectivity.', $host);
		}

		return $name . ' API request failed.';
	}

	/**
	 * Human display name for a forge, used in error messages.
	 */
	private function forgeName(Forge $forge): string {
		return $forge->id === ForgeRegistry::FORGE_GITHUB ? 'GitHub' : ucfirst($forge->id);
	}
}
