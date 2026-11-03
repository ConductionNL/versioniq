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
use OCA\Versioniq\Service\Advisory\AdvisoryClauses;
use OCA\Versioniq\Service\Advisory\AdvisorySeverity;
use OCA\Versioniq\Service\Advisory\AdvisorySourceInterface;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCA\Versioniq\Service\Pat\PatAccess;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
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
 * matches and is visible to the current admin, or, in a background job with no
 * session user, when one matches and is shared with admins.
 *
 * @psalm-api
 */
class ForgeReleaseSource implements SourceInterface, AdvisorySourceInterface {
	private const USER_AGENT = 'Nextcloud-Versioniq';

	/** Error text after the forge name, per HTTP status. */
	private const STATUS_ERRORS = [
		404 => ' repository not found.',
		401 => ' authentication failed — the configured PAT may be revoked or expired.',
		403 => ' rate limit exceeded — try again later, or configure a PAT.',
	];

	/**
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private PatAccess $pats,
		private ForgeRegistry $forgeRegistry,
		private IConfig $config,
		// Tells integriq what a GitHub request met (adopt-connection-registry).
		// Optional and last, so every existing caller and test keeps working.
		private ?ConnectionReportService $connectionReports = null,
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
				// A draft carries no published_at and stays null.
				'releasedAt' => ReleaseDate::normalize($release['published_at'] ?? null),
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
		$severity = $entry['severity'] ?? null;
		/** @var mixed $summary */
		$summary = $entry['summary'] ?? ($entry['title'] ?? '');

		[$affected, $firstPatched] = $this->firstVulnerability($entry['vulnerabilities'] ?? []);

		return [
			'id' => $id,
			'severity' => AdvisorySeverity::normalize($severity),
			'summary' => is_string($summary) ? $summary : '',
			'affected' => $affected,
			'firstPatchedVersion' => $firstPatched,
		];
	}

	/**
	 * The affected clauses and first patched version of the first entry of
	 * an advisory's vulnerability list (forge advisories list one package).
	 *
	 * @return array{0: list<string>, 1: ?string}
	 */
	private function firstVulnerability(mixed $vulnerabilities): array {
		$vuln = is_array($vulnerabilities) ? ($vulnerabilities[0] ?? null) : null;
		if (!is_array($vuln)) {
			return [[], null];
		}
		/** @var mixed $patched */
		$patched = $vuln['first_patched_version'] ?? null;
		if (is_array($patched)) {
			$patched = $patched['identifier'] ?? null;
		}

		return [
			AdvisoryClauses::from($vuln['vulnerable_version_range'] ?? null),
			is_string($patched) && $patched !== '' ? $this->normalizeVersion($patched) : null,
		];
	}

	/**
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function fetchAdvisories(Forge $forge, string $ownerRepo): array {
		// No session user means a background job; the resolver then picks from
		// tokens shared with admins instead of going anonymous (#430).
		$uid = $this->pats->userSession->getUser()?->getUID();
		$pat = $this->pats->patResolver->findFor($forge->id, $ownerRepo, $uid);

		$endpoint = $forge->advisoriesEndpoint($ownerRepo);

		if ($pat === null) {
			return $this->performFetch($forge, $endpoint, null);
		}

		/** @var array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string} */
		return $this->pats->patManager->useToken($pat, fn (string $token): array => $this->performFetch($forge, $endpoint, $token));
	}

	/**
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function fetchReleases(Forge $forge, string $ownerRepo): array {
		// No session user means a background job; the resolver then picks from
		// tokens shared with admins instead of going anonymous (#430).
		$uid = $this->pats->userSession->getUser()?->getUID();
		$pat = $this->pats->patResolver->findFor($forge->id, $ownerRepo, $uid);

		$endpoint = $forge->releasesEndpoint($ownerRepo);

		if ($pat === null) {
			return $this->performFetch($forge, $endpoint, null);
		}

		/** @var array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string} */
		return $this->pats->patManager->useToken($pat, fn (string $token): array => $this->performFetch($forge, $endpoint, $token));
	}

	/**
	 * Performs one forge request, and tells integriq what GitHub answered.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function performFetch(Forge $forge, string $endpoint, ?string $token): array {
		try {
			$response = $this->clientService->newClient()->get($endpoint, [
				'headers' => $this->requestHeaders($forge, $token),
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

			$this->connectionReports?->forgeAnswered($forge->id, $forge->apiBaseUrl, null);

			return ['ok' => false, 'error' => $this->humanizeError($forge, $error->getMessage())];
		}

		return $this->readReleaseList($forge, $response);
	}

	/**
	 * Request headers for one forge call: GitHub gets its own content
	 * negotiation, and a token goes in the forge's auth scheme.
	 *
	 * @return array<string, string>
	 */
	private function requestHeaders(Forge $forge, ?string $token): array {
		$headers = [
			'Accept' => 'application/json',
			'User-Agent' => self::USER_AGENT,
		];
		if ($forge->id === ForgeRegistry::FORGE_GITHUB) {
			$headers['Accept'] = 'application/vnd.github+json';
			$headers['X-GitHub-Api-Version'] = '2022-11-28';
		}
		if ($token !== null) {
			$headers['Authorization'] = $forge->authHeaderValue($token);
		}

		return $headers;
	}

	/**
	 * Reads a forge answer as a release list, and tells integriq what it met.
	 *
	 * @return array{ok: true, releases: array<int, mixed>}|array{ok: false, error: string}
	 */
	private function readReleaseList(Forge $forge, IResponse $response): array {
		$status = $response->getStatusCode();
		if ($status !== 200) {
			$this->connectionReports?->forgeAnswered($forge->id, $forge->apiBaseUrl, $status);

			return ['ok' => false, 'error' => $this->statusError($forge, $status)];
		}

		try {
			$decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$this->connectionReports?->forgeAnswered($forge->id, $forge->apiBaseUrl, $status, false);

			return ['ok' => false, 'error' => $this->forgeName($forge) . ' API returned malformed JSON.'];
		}

		if (!is_array($decoded) || !array_is_list($decoded)) {
			$this->connectionReports?->forgeAnswered($forge->id, $forge->apiBaseUrl, $status, false);

			return ['ok' => false, 'error' => $this->forgeName($forge) . ' API returned an unexpected payload shape.'];
		}

		$this->connectionReports?->forgeAnswered($forge->id, $forge->apiBaseUrl, $status);

		return ['ok' => true, 'releases' => $decoded];
	}

	/**
	 * The error text for a forge answer that is not HTTP 200.
	 */
	private function statusError(Forge $forge, int $status): string {
		$suffix = self::STATUS_ERRORS[$status] ?? null;
		if ($suffix !== null) {
			return $this->forgeName($forge) . $suffix;
		}

		return sprintf('%s API returned HTTP %d.', $this->forgeName($forge), $status);
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

		[$matchingAssets, $shaUrl] = $this->scanAssets($assets, $assetPattern);
		$error = $this->assetMatchError($matchingAssets, $assetPattern);
		if ($error !== null) {
			return ['error' => $error];
		}

		return [
			'kind' => 'github-release',
			'download' => $matchingAssets[0]['url'],
			'assetName' => $matchingAssets[0]['name'],
			'sha256Url' => $shaUrl,
		] + $this->tagFields($release);
	}

	/**
	 * The assets whose name matches the pattern, and the URL of the last
	 * `.sha256` asset anywhere in the release.
	 *
	 * @param list<mixed> $assets
	 * @return array{0: list<array{name: string, url: string}>, 1: ?string}
	 */
	private function scanAssets(array $assets, string $assetPattern): array {
		$matchingAssets = [];
		$shaUrl = null;
		/** @var mixed $asset */
		foreach ($assets as $asset) {
			$named = $this->namedAsset($asset);
			if ($named === null) {
				continue;
			}
			if (fnmatch($assetPattern, $named['name'], FNM_NOESCAPE)) {
				$matchingAssets[] = $named;
			}
			if (str_ends_with($named['name'], '.sha256')) {
				$shaUrl = $named['url'];
			}
		}

		return [$matchingAssets, $shaUrl];
	}

	/**
	 * An asset's name and download URL, or null when either is missing,
	 * empty or not a string.
	 *
	 * @return array{name: string, url: string}|null
	 */
	private function namedAsset(mixed $asset): ?array {
		if (!is_array($asset)) {
			return null;
		}
		$name = self::nonEmptyString($asset['name'] ?? null);
		$url = self::nonEmptyString($asset['browser_download_url'] ?? null);
		if ($name === null || $url === null) {
			return null;
		}

		return ['name' => $name, 'url' => $url];
	}

	private static function nonEmptyString(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Why the matching assets cannot be installed: none, or more than one.
	 *
	 * @param list<array{name: string, url: string}> $matchingAssets
	 */
	private function assetMatchError(array $matchingAssets, string $assetPattern): ?string {
		if (count($matchingAssets) === 0) {
			return sprintf('No release asset matches pattern "%s".', $assetPattern);
		}
		if (count($matchingAssets) === 1) {
			return null;
		}
		$names = array_map(static fn (array $a): string => $a['name'], $matchingAssets);

		return sprintf(
			'Multiple matching assets for pattern "%s" (%s) — set explicit assetPattern.',
			$assetPattern,
			implode(', ', $names)
		);
	}

	/**
	 * The release's version (tag without a leading v) and raw tag name.
	 *
	 * @param array<array-key, mixed> $release
	 * @return array{version: string, tagName: string}
	 */
	private function tagFields(array $release): array {
		/** @var mixed $tag */
		$tag = $release['tag_name'] ?? '';
		if (!is_string($tag)) {
			return ['version' => '', 'tagName' => ''];
		}

		return ['version' => $this->normalizeVersion($tag), 'tagName' => $tag];
	}

	private function normalizeVersion(string $tag): string {
		if (str_starts_with($tag, 'v') || str_starts_with($tag, 'V')) {
			return substr($tag, 1);
		}

		return $tag;
	}

	/**
	 * @param list<array{version: string, changelog: ?string, releasedAt: ?string}> $versions
	 * @return list<array{version: string, changelog: ?string, releasedAt: ?string}>
	 */
	private function dedupeAndSort(array $versions): array {
		$seenAt = [];
		/** @var list<array{version: string, changelog: ?string, releasedAt: ?string}> $unique */
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
			if ($unique[$index]['releasedAt'] === null && $entry['releasedAt'] !== null) {
				$unique[$index]['releasedAt'] = $entry['releasedAt'];
			}
		}

		usort(
			$unique,
			/**
			 * @param array{version: string, changelog: ?string, releasedAt: ?string} $a
			 * @param array{version: string, changelog: ?string, releasedAt: ?string} $b
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
