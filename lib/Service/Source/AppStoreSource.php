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
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Advisory\AdvisorySourceInterface;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\L10N\IFactory;
use Throwable;
use UnexpectedValueException;

/**
 * Adapter for the Nextcloud App Store as a release source. Wraps the existing
 * app-store fetch logic that previously lived inline in `InstallerService` so
 * the installer can treat App Store and GitHub origins uniformly.
 *
 * The App Store path uses the full code-signing chain — install is dispatched
 * to `SelectedReleaseInstallerService`, not the external installer.
 *
 * @psalm-api
 */
class AppStoreSource implements SourceInterface, AdvisorySourceInterface {
	private const DEFAULT_API_BASE = 'https://garm3.nextcloud.com/api/v1';
	private const MAX_PAGES = 20;

	/**
	 * How long a resolved app payload stays usable before it is refetched.
	 *
	 * The App Store `apps.json` endpoint ignores its `filter` parameter and
	 * answers with the entire catalogue — ~30 MB / ~60 s per call, measured
	 * against garm3 on 2026-07-24. Without a cache every version listing,
	 * advisory correlation and install pre-check paid that cost again, which is
	 * the app's core flow. An hour — matching the discovery catalogue cache —
	 * keeps a newly published release visible reasonably quickly while making
	 * that expensive round trip rare.
	 */
	private const PAYLOAD_CACHE_TTL_SECONDS = 3600;

	/**
	 * Ceiling for a catalogue round trip. The payload is large enough that the
	 * default client timeout can abort it midway, which would surface as "no
	 * versions available" rather than a clear failure.
	 */
	private const FETCH_TIMEOUT_SECONDS = 180;
	private const PAYLOAD_CACHE_PREFIX = 'appstore.payload.';
	private const PAYLOAD_CACHE_TS_PREFIX = 'appstore.payload_ts.';

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IFactory $l10nFactory,
	) {
	}

	public function getKind(): string {
		return SourceBinding::KIND_APPSTORE;
	}

	public function getInstallerKind(): string {
		return self::INSTALLER_SIGNED;
	}

	/**
	 * Lists App Store releases for an app, normalized newest-first; see "Fetch Available Versions"
	 * and "Version listings carry release notes".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/changelog-visibility/spec.md
	 */
	public function listVersions(string $appId, SourceBinding $binding): array {
		try {
			$payload = $this->fetchAppPayload($appId);
		} catch (Exception $error) {
			return ['versions' => [], 'error' => 'Could not fetch versions from the app store: ' . $error->getMessage()];
		}

		if ($payload === null) {
			return ['versions' => [], 'error' => 'App is not available in the Nextcloud App Store.'];
		}

		$releases = $payload['releases'] ?? [];
		if (!is_array($releases)) {
			return ['versions' => [], 'error' => 'App store returned an unexpected payload shape.'];
		}

		return ['versions' => $this->normalizeVersions($releases), 'error' => null];
	}

	/**
	 * Resolves a single App Store release (with certificate) for install; see "Install Specific Version".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array {
		$payload = $this->fetchAppPayload($appId);
		if ($payload === null || !isset($payload['releases']) || !is_array($payload['releases'])) {
			return null;
		}

		/** @var mixed $release */
		foreach ($payload['releases'] as $release) {
			if (!is_array($release)) {
				continue;
			}
			if (($release['version'] ?? null) === $version) {
				/** @var array<string, mixed> $resolved */
				$resolved = array_merge(
					$release,
					[
						'certificate' => $payload['certificate'] ?? null,
						'kind' => 'appstore',
					],
				);

				return $resolved;
			}
		}

		return null;
	}

	/**
	 * Lists security advisories the App Store publishes for an app. The App
	 * Store app payload may carry a `securityAdvisories` list (id, severity,
	 * summary, affected version clauses, first patched version); when the feed
	 * does not carry advisory data for an app, an empty list is returned (a
	 * clean state, not an error). Reuses the existing app-payload fetch — no
	 * new HTTP client.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @return array{advisories: list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}>, error: ?string}
	 */
	public function listAdvisories(string $appId, SourceBinding $binding): array {
		try {
			$payload = $this->fetchAppPayload($appId);
		} catch (Exception $error) {
			return ['advisories' => [], 'error' => 'Could not fetch advisories from the app store: ' . $error->getMessage()];
		}

		if ($payload === null) {
			return ['advisories' => [], 'error' => null];
		}

		/** @var mixed $raw */
		$raw = $payload['securityAdvisories'] ?? $payload['security_advisories'] ?? null;
		if (!is_array($raw)) {
			return ['advisories' => [], 'error' => null];
		}

		return ['advisories' => $this->normalizeAdvisories($raw), 'error' => null];
	}

	/**
	 * @param array<array-key, mixed> $raw
	 * @return list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}>
	 */
	private function normalizeAdvisories(array $raw): array {
		$advisories = [];
		/** @var mixed $entry */
		foreach ($raw as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			/** @var mixed $id */
			$id = $entry['id'] ?? $entry['ghsa_id'] ?? null;
			if (!is_string($id) || $id === '') {
				continue;
			}
			$severity = $entry['severity'] ?? 'medium';
			$summary = $entry['summary'] ?? ($entry['title'] ?? '');
			$affected = [];
			/** @var mixed $affectedRaw */
			$affectedRaw = $entry['affected'] ?? $entry['affectedVersions'] ?? [];
			if (is_string($affectedRaw)) {
				$affectedRaw = array_map('trim', explode(',', $affectedRaw));
			}
			if (is_array($affectedRaw)) {
				/** @var mixed $clause */
				foreach ($affectedRaw as $clause) {
					if (is_string($clause) && trim($clause) !== '') {
						$affected[] = trim($clause);
					}
				}
			}
			/** @var mixed $patched */
			$patched = $entry['firstPatchedVersion'] ?? $entry['first_patched_version'] ?? null;

			$advisories[] = [
				'id' => $id,
				'severity' => is_string($severity) ? strtolower($severity) : 'medium',
				'summary' => is_string($summary) ? $summary : '',
				'affected' => $affected,
				'firstPatchedVersion' => is_string($patched) && $patched !== '' ? $patched : null,
			];
		}

		return $advisories;
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	private function fetchAppPayload(string $appId): ?array {
		$cached = $this->readCachedPayload($appId, false);
		if ($cached !== null) {
			return $cached;
		}

		$payload = $this->fetchAppPayloadUncached($appId);
		if ($payload !== null) {
			$this->writeCachedPayload($appId, $payload);

			return $payload;
		}

		// The live fetch failed (App Store outage, a 200-with-empty-body episode,
		// a timeout, …). Rather than blank every listing, fall back to the last
		// cached payload even though its TTL has lapsed — stale-if-error. A flaky
		// upstream is the whole reason this cache exists.
		return $this->readCachedPayload($appId, true);
	}

	/**
	 * Returns a cached payload for the app, or null when there is none or the
	 * stored JSON is malformed. When $ignoreTtl is false the entry is only
	 * returned while still within its TTL (the normal fast path); when true the
	 * age check is skipped so a stale copy can serve as a last resort during an
	 * upstream outage.
	 *
	 * @return array<array-key, mixed>|null
	 */
	private function readCachedPayload(string $appId, bool $ignoreTtl): ?array {
		if (!$ignoreTtl) {
			$cachedAt = (int)$this->appConfig->getValueString(
				Application::APP_ID,
				self::PAYLOAD_CACHE_TS_PREFIX . $appId,
				'0',
			);
			if ($cachedAt <= 0 || (time() - $cachedAt) >= self::PAYLOAD_CACHE_TTL_SECONDS) {
				return null;
			}
		}

		$raw = $this->appConfig->getValueString(Application::APP_ID, self::PAYLOAD_CACHE_PREFIX . $appId, '');
		if ($raw === '') {
			return null;
		}

		try {
			/** @var mixed $decoded */
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable) {
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Stores a resolved payload for reuse. Caching is best-effort: a failure to
	 * write must never break a listing that already succeeded.
	 *
	 * @param array<array-key, mixed> $payload
	 */
	private function writeCachedPayload(string $appId, array $payload): void {
		try {
			$this->appConfig->setValueString(
				Application::APP_ID,
				self::PAYLOAD_CACHE_PREFIX . $appId,
				json_encode($payload, JSON_THROW_ON_ERROR),
			);
			$this->appConfig->setValueString(
				Application::APP_ID,
				self::PAYLOAD_CACHE_TS_PREFIX . $appId,
				(string)time(),
			);
		} catch (Throwable) {
			// Cache write problems are non-fatal by design.
		}
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	/**
	 * The App Store API base URL. Defaults to the public store but MAY be
	 * overridden via the `appstore.api_base` app config so an instance can point
	 * at a mirror (or, in an e2e environment, at a fixture). A blank override
	 * keeps the default; a trailing slash is trimmed.
	 */
	private function apiBase(): string {
		/** @var string|null $raw */
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'appstore.api_base', '');
		$override = trim((string)$raw);

		return rtrim($override !== '' ? $override : self::DEFAULT_API_BASE, '/');
	}

	private function fetchAppPayloadUncached(string $appId): ?array {
		$client = $this->clientService->newClient();

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$endpoint = $this->apiBase() . '/apps.json?filter=' . rawurlencode($appId) . '&page=' . $page;
			try {
				$response = $client->get($endpoint, ['timeout' => self::FETCH_TIMEOUT_SECONDS]);
				if ($response->getStatusCode() !== 200) {
					continue;
				}
				$body = trim((string)$response->getBody());
				if ($body === '') {
					return null;
				}
				/** @var mixed $decoded */
				$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($decoded)) {
					return null;
				}
				// The whole catalogue arrived regardless of the filter; keep it.
				$this->cacheCatalogueEntries($decoded);
				$appPayload = $this->extractAppPayload($decoded, $appId);
				if (is_array($appPayload)) {
					return $appPayload;
				}
				if (!$this->hasPossibleNextPage($decoded, $page)) {
					break;
				}
			} catch (Exception) {
				continue;
			}
		}

		$platformVersion = $this->getPlatformVersion();
		$platformEndpoint = $this->apiBase() . '/platform/' . rawurlencode($platformVersion) . '/apps.json';

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$endpoint = $platformEndpoint . '?page=' . $page;
			try {
				$response = $client->get($endpoint, ['timeout' => self::FETCH_TIMEOUT_SECONDS]);
				if ($response->getStatusCode() !== 200) {
					continue;
				}
				$body = trim((string)$response->getBody());
				if ($body === '') {
					continue;
				}
				/** @var mixed $decoded */
				$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
				if (!is_array($decoded)) {
					continue;
				}
				// Same reasoning as the filtered endpoint above: this response
				// is the whole platform catalogue, so index all of it.
				$this->cacheCatalogueEntries($decoded);
				$appPayload = $this->extractAppPayload($decoded, $appId);
				if (is_array($appPayload)) {
					return $appPayload;
				}
				if (!$this->hasPossibleNextPage($decoded, $page)) {
					break;
				}
			} catch (Exception) {
				continue;
			}
		}

		return null;
	}

	/**
	 * @param array<array-key, mixed> $payload
	 * @return array<array-key, mixed>|null
	 */
	private function extractAppPayload(array $payload, string $appId): ?array {
		$data = $this->arrayField($payload, 'data');
		if ($data !== null && array_is_list($data)) {
			$match = $this->findById($data, $appId);
			if ($match !== null) {
				return $match;
			}
		}

		if (array_is_list($payload)) {
			return $this->findById($payload, $appId);
		}

		$apps = $this->arrayField($payload, 'apps');
		if ($apps === null) {
			return null;
		}

		return $this->findById($apps, $appId);
	}

	/**
	 * Returns the named field as an array, or null when absent/non-array.
	 *
	 * @param array<array-key, mixed> $payload
	 * @return array<array-key, mixed>|null
	 */
	private function arrayField(array $payload, string $key): ?array {
		/** @var mixed $value */
		$value = $payload[$key] ?? null;

		return is_array($value) ? $value : null;
	}

	/**
	 * Finds the first list entry whose `id` matches the given app id.
	 *
	 * @param array<array-key, mixed> $entries
	 * @return array<array-key, mixed>|null
	 */
	/**
	 * Caches EVERY app in a freshly-downloaded catalogue, not just the one that
	 * was asked for.
	 *
	 * The App Store's `apps.json` IGNORES its `filter` parameter — measured
	 * 2026-08-21, `?filter=notes` returned all 755 entries and 31.7 MB — so a
	 * lookup for one app already pays for the whole catalogue. Keeping one
	 * entry and discarding 754 meant a full advisory sweep over 88 enabled
	 * apps downloaded ~31.7 MB per app, and did it twice per app because
	 * `listAdvisories()` and `listVersions()` each resolve a payload.
	 *
	 * Indexing the whole response makes the FIRST lookup pay for the download
	 * and every subsequent app in the same sweep a cache hit. Nothing else
	 * changes: entries are written through the same per-app cache with the same
	 * TTL, so a caller asking for one app in isolation behaves exactly as before.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param array<array-key, mixed> $decoded A decoded catalogue response.
	 */
	private function cacheCatalogueEntries(array $decoded): void {
		$entries = null;
		$data = $this->arrayField($decoded, 'data');
		if ($data !== null && array_is_list($data)) {
			$entries = $data;
		} elseif (array_is_list($decoded)) {
			$entries = $decoded;
		} else {
			$apps = $this->arrayField($decoded, 'apps');
			if ($apps !== null && array_is_list($apps)) {
				$entries = $apps;
			}
		}

		if ($entries === null) {
			return;
		}

		/** @var mixed $entry */
		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			/** @var mixed $id */
			$id = $entry['id'] ?? null;
			if (!is_string($id) || $id === '') {
				continue;
			}
			$this->writeCachedPayload($id, $entry);
		}
	}

	private function findById(array $entries, string $appId): ?array {
		/** @var mixed $entry */
		foreach ($entries as $entry) {
			if (is_array($entry) && ($entry['id'] ?? null) === $appId) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * @param array<array-key, mixed> $payload
	 */
	private function hasPossibleNextPage(array $payload, int $currentPage): bool {
		if (isset($payload['page'])) {
			$current = (int)$payload['page'];
			if ($current > 0 && $current !== $currentPage) {
				return false;
			}
		}
		if (isset($payload['pages']['next']) && is_bool($payload['pages']['next'])) {
			return $payload['pages']['next'];
		}
		if (isset($payload['pagination']['next_page'])) {
			return $payload['pagination']['next_page'] !== null;
		}
		if (isset($payload['nextPage']) && is_string($payload['nextPage'])) {
			return $payload['nextPage'] !== '';
		}
		$apps = $this->arrayField($payload, 'apps');
		if ($apps !== null) {
			return count($apps) > 0;
		}
		$data = $this->arrayField($payload, 'data');
		if ($data !== null) {
			return count($data) > 0;
		}

		return false;
	}

	private function getPlatformVersion(): string {
		$version = $this->config->getSystemValueString('version');
		$parts = explode('.', $version);
		$major = $parts[0] ?? '0';
		$minor = $parts[1] ?? '0';
		if (!ctype_digit($major) || !ctype_digit($minor)) {
			return '0.0.0';
		}

		return $major . '.' . $minor . '.0';
	}

	/**
	 * @param array<mixed> $releases
	 * @return list<array{version: string, changelog: ?string}>
	 */
	private function normalizeVersions(array $releases): array {
		/** @var array<string, ?string> $changelogsByVersion */
		$changelogsByVersion = [];
		$order = [];
		/** @var mixed $release */
		foreach ($releases as $release) {
			if (is_string($release)) {
				$version = $release;
				$changelog = null;
			} elseif (is_array($release)) {
				/** @var mixed $version */
				$version = $release['version'] ?? $release['ver'] ?? $release['name'] ?? $release['tag_name'] ?? null;
				if (!is_string($version) || $version === '') {
					continue;
				}
				$changelog = $this->extractChangelog($release);
			} else {
				continue;
			}

			if (!array_key_exists($version, $changelogsByVersion)) {
				$order[] = $version;
				$changelogsByVersion[$version] = $changelog;
			} elseif ($changelogsByVersion[$version] === null && $changelog !== null) {
				$changelogsByVersion[$version] = $changelog;
			}
		}

		usort($order, static fn (string $a, string $b): int => version_compare($b, $a));

		return array_map(
			static fn (string $version): array => ['version' => $version, 'changelog' => $changelogsByVersion[$version]],
			$order
		);
	}

	/**
	 * Maps a release's changelog from its `translations` block, preferring
	 * the requested UI language and falling back to `en`. Fail-soft: any
	 * mapping failure (unexpected payload shape) is caught and yields
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
		/** @var mixed $translations */
		$translations = $release['translations'] ?? null;
		if ($translations === null) {
			return null;
		}
		if (!is_array($translations)) {
			throw new UnexpectedValueException('translations is not an array.');
		}

		$lang = $this->l10nFactory->findLanguage();
		/** @var mixed $entry */
		$entry = $translations[$lang] ?? $translations['en'] ?? null;
		if ($entry === null) {
			return null;
		}
		if (!is_array($entry)) {
			throw new UnexpectedValueException('translation entry is not an array.');
		}

		/** @var mixed $changelog */
		$changelog = $entry['changelog'] ?? null;
		if ($changelog === null) {
			return null;
		}
		if (!is_string($changelog)) {
			throw new UnexpectedValueException('changelog is not a string.');
		}

		return trim($changelog) === '' ? null : $changelog;
	}
}
