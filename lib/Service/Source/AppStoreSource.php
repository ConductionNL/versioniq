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
use OCA\Versioniq\Service\Advisory\AdvisoryClauses;
use OCA\Versioniq\Service\Advisory\AdvisorySeverity;
use OCA\Versioniq\Service\Advisory\AdvisorySourceInterface;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCP\Http\Client\IClient;
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
 * @psalm-type VersionEntry = array{version: string, changelog: ?string, serverCompatible: ?bool, releasedAt: ?string}
 */
class AppStoreSource implements SourceInterface, AdvisorySourceInterface {
	public const DEFAULT_API_BASE = 'https://garm3.nextcloud.com/api/v1';
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

	/**
	 * Key prefixes of the payload cache. Public so the repair step that marks
	 * existing rows lazy names the same keys this class writes.
	 *
	 * EVERY READ AND WRITE OF THESE KEYS PASSES `lazy: true`. One payload per
	 * managed app, and a single one (mail) runs to ~4 MB, so the cache grows to
	 * tens of megabytes. Nextcloud loads every NON-lazy app-config row on every
	 * request, for every app, so an eager cache made the whole instance pay for
	 * it: measured 2026-10-06, 1,660 eager rows / 36 MB took ~300 ms of a
	 * ~450 ms `status.php`, and 55 ms once marked lazy.
	 *
	 * @see \OCA\Versioniq\Repair\MarkPayloadCacheLazy
	 */
	public const PAYLOAD_CACHE_PREFIX = 'appstore.payload.';
	public const PAYLOAD_CACHE_TS_PREFIX = 'appstore.payload_ts.';

	/**
	 * What the current uncached fetch met, for the connection report
	 * (adopt-connection-registry). Reset at the start of every fetch.
	 *
	 * @var array{answered: bool, failure: string}
	 */
	private array $catalogueOutcome = ['answered' => false, 'failure' => ''];

	/**
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IFactory $l10nFactory,
		// Tells integriq whether the App Store answered. Optional and last, so
		// every existing caller and test keeps working.
		private ?ConnectionReportService $connectionReports = null,
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
			$advisory = is_array($entry) ? self::advisoryFrom($entry) : null;
			if ($advisory !== null) {
				$advisories[] = $advisory;
			}
		}

		return $advisories;
	}

	/**
	 * One App Store advisory entry as an advisory record, or null without an id.
	 *
	 * @param array<array-key, mixed> $entry
	 * @return array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}|null
	 */
	private static function advisoryFrom(array $entry): ?array {
		/** @var mixed $id */
		$id = $entry['id'] ?? $entry['ghsa_id'] ?? null;
		if (!is_string($id) || $id === '') {
			return null;
		}
		/** @var mixed $summary */
		$summary = $entry['summary'] ?? ($entry['title'] ?? '');
		/** @var mixed $patched */
		$patched = $entry['firstPatchedVersion'] ?? $entry['first_patched_version'] ?? null;

		return [
			'id' => $id,
			'severity' => AdvisorySeverity::normalize($entry['severity'] ?? null),
			'summary' => is_string($summary) ? $summary : '',
			'affected' => AdvisoryClauses::from($entry['affected'] ?? $entry['affectedVersions'] ?? []),
			'firstPatchedVersion' => is_string($patched) && $patched !== '' ? $patched : null,
		];
	}

	/**
	 * @return array<array-key, mixed>|null
	 */
	private function fetchAppPayload(string $appId): ?array {
		$cached = $this->readCachedPayload($appId, false);
		if ($cached !== null) {
			return $cached;
		}

		$this->catalogueOutcome = ['answered' => false, 'failure' => ''];
		$payload = $this->fetchAppPayloadUncached($appId);
		// Only an uncached fetch reaches this line, so a cache hit reports
		// nothing and a report follows a real catalogue request.
		$this->connectionReports?->appStoreFetched($this->catalogueOutcome['answered'], $this->catalogueOutcome['failure']);
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
				lazy: true,
			);
			if ($cachedAt <= 0 || (time() - $cachedAt) >= self::PAYLOAD_CACHE_TTL_SECONDS) {
				return null;
			}
		}

		$raw = $this->appConfig->getValueString(Application::APP_ID, self::PAYLOAD_CACHE_PREFIX . $appId, '', lazy: true);
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
				lazy: true,
			);
			$this->appConfig->setValueString(
				Application::APP_ID,
				self::PAYLOAD_CACHE_TS_PREFIX . $appId,
				(string)time(),
				lazy: true,
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

		$filteredQuery = new CatalogueQuery(
			$appId,
			fn (int $page): string => $this->apiBase() . '/apps.json?filter=' . rawurlencode($appId) . '&page=' . $page,
		);
		$filteredQuery->stopOnBadBody = true;
		$filtered = $this->searchCatalogue($client, $filteredQuery);
		if ($filtered !== null) {
			return $filtered === false ? null : $filtered;
		}

		$platformVersion = $this->getPlatformVersion();
		$platformEndpoint = $this->apiBase() . '/platform/' . rawurlencode($platformVersion) . '/apps.json';

		// Same reasoning as the filtered endpoint: each response is the whole
		// platform catalogue, so every page is indexed.
		$platform = $this->searchCatalogue($client, new CatalogueQuery(
			$appId,
			static fn (int $page): string => $platformEndpoint . '?page=' . $page,
		));

		return $platform === false ? null : $platform;
	}

	/**
	 * Walks the query's pages until the app turns up or no next page can
	 * exist. Returns the app payload, null when it was not found, or false
	 * when a bad body ends the lookup.
	 *
	 * @return array<array-key, mixed>|false|null
	 */
	private function searchCatalogue(IClient $client, CatalogueQuery $query): array|false|null {
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$decoded = $this->readCataloguePage($client, ($query->endpoint)($page));
			if ($decoded === null || ($decoded === false && !$query->stopOnBadBody)) {
				continue;
			}
			if ($decoded === false) {
				return false;
			}
			$this->catalogueOutcome['answered'] = true;
			// The whole catalogue arrived regardless of the filter; keep it.
			$this->cacheCatalogueEntries($decoded);
			$appPayload = $this->extractAppPayload($decoded, $query->appId);
			if (is_array($appPayload)) {
				return $appPayload;
			}
			if (!$this->hasPossibleNextPage($decoded, $page)) {
				break;
			}
		}

		return null;
	}

	/**
	 * Fetches and decodes one catalogue page, noting each failure for the
	 * connection report. Null skips the page (an error status or no readable
	 * answer); false is an empty or non-array body.
	 *
	 * @return array<array-key, mixed>|false|null
	 */
	private function readCataloguePage(IClient $client, string $endpoint): array|false|null {
		try {
			$response = $client->get($endpoint, ['timeout' => self::FETCH_TIMEOUT_SECONDS]);
			if ($response->getStatusCode() !== 200) {
				$this->noteCatalogueFailure('HTTP ' . $response->getStatusCode());
				return null;
			}
			$body = trim((string)$response->getBody());
			if ($body === '') {
				$this->noteCatalogueFailure('an empty body');
				return false;
			}
			/** @var mixed $decoded */
			$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($decoded)) {
				$this->noteCatalogueFailure('an unexpected payload');
				return false;
			}

			return $decoded;
		} catch (Exception) {
			$this->noteCatalogueFailure('no readable answer');
			return null;
		}
	}

	/**
	 * Records why a catalogue request failed, for the connection report.
	 *
	 * The reason names only what came back, never the endpoint: the message
	 * lands on a row every admin reads.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	private function noteCatalogueFailure(string $reason): void {
		$this->catalogueOutcome['failure'] = 'the last attempt got ' . $reason . '.';
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
		/** @var mixed $entry */
		foreach ($this->catalogueEntries($decoded) ?? [] as $entry) {
			/** @var mixed $id */
			$id = is_array($entry) ? ($entry['id'] ?? null) : null;
			if (is_string($id) && $id !== '') {
				/** @var array<array-key, mixed> $entry */
				$this->writeCachedPayload($id, $entry);
			}
		}
	}

	/**
	 * The app list of a catalogue response: its `data` list, the response
	 * itself when it is a list, or its `apps` list; null when it has none.
	 *
	 * @param array<array-key, mixed> $decoded
	 * @return list<mixed>|null
	 */
	private function catalogueEntries(array $decoded): ?array {
		$data = $this->arrayField($decoded, 'data');
		if ($data !== null && array_is_list($data)) {
			return $data;
		}
		if (array_is_list($decoded)) {
			return $decoded;
		}
		$apps = $this->arrayField($decoded, 'apps');

		return $apps !== null && array_is_list($apps) ? $apps : null;
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

		return self::statedNextPage($payload) ?? $this->hasEntries($payload);
	}

	/**
	 * What the response itself says about a next page, in the shapes the App
	 * Store and its mirrors use; null when it says nothing.
	 *
	 * @param array<array-key, mixed> $payload
	 */
	private static function statedNextPage(array $payload): ?bool {
		if (isset($payload['pages']['next']) && is_bool($payload['pages']['next'])) {
			return $payload['pages']['next'];
		}
		// An explicit `next_page: null` is the App Store saying this is the last page.
		/** @var mixed $pagination */
		$pagination = $payload['pagination'] ?? null;
		if (is_array($pagination) && array_key_exists('next_page', $pagination)) {
			return $pagination['next_page'] !== null;
		}
		if (isset($payload['nextPage']) && is_string($payload['nextPage'])) {
			return $payload['nextPage'] !== '';
		}

		return null;
	}

	/**
	 * Whether the page carried any apps, which is the only hint left.
	 *
	 * @param array<array-key, mixed> $payload
	 */
	private function hasEntries(array $payload): bool {
		$apps = $this->arrayField($payload, 'apps');
		if ($apps !== null) {
			return count($apps) > 0;
		}
		$data = $this->arrayField($payload, 'data');

		return $data !== null && count($data) > 0;
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
	 * Normalises the release list, newest first. Each entry carries
	 * `serverCompatible`: whether the running server satisfies the release's
	 * `platformVersionSpec`, or null when the release does not say; see
	 * "Fetch Available Versions".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @param array<mixed> $releases
	 * @return list<array{version: string, changelog: ?string, serverCompatible: ?bool, releasedAt: ?string}>
	 */
	private function normalizeVersions(array $releases): array {
		$serverVersion = $this->getServerVersion();
		/** @var array<string, VersionEntry> $byVersion */
		$byVersion = [];
		/** @var mixed $release */
		foreach ($releases as $release) {
			$entry = $this->releaseEntry($release, $serverVersion);
			if ($entry !== null) {
				$byVersion[$entry['version']] = self::mergeEntry($byVersion[$entry['version']] ?? null, $entry);
			}
		}

		$entries = array_values($byVersion);
		usort($entries, self::newestFirst(...));

		return $entries;
	}

	/**
	 * One release entry as a version row, or null when it names no version.
	 * A bare string is the version itself, with nothing else known.
	 *
	 * @return VersionEntry|null
	 */
	private function releaseEntry(mixed $release, string $serverVersion): ?array {
		if (is_string($release)) {
			return ['version' => $release, 'changelog' => null, 'serverCompatible' => null, 'releasedAt' => null];
		}

		return is_array($release) ? $this->arrayReleaseEntry($release, $serverVersion) : null;
	}

	/**
	 * @return VersionEntry|null
	 */
	private function arrayReleaseEntry(array $release, string $serverVersion): ?array {
		/** @var mixed $version */
		$version = $release['version'] ?? $release['ver'] ?? $release['name'] ?? $release['tag_name'] ?? null;
		if (!is_string($version) || $version === '') {
			return null;
		}
		/** @var mixed $spec */
		$spec = $release['platformVersionSpec'] ?? null;

		return [
			'version' => $version,
			'changelog' => $this->extractChangelog($release),
			'serverCompatible' => is_string($spec) ? self::satisfiesPlatformSpec($serverVersion, $spec) : null,
			'releasedAt' => ReleaseDate::normalize($release['created'] ?? null),
		];
	}

	/**
	 * @param VersionEntry $a
	 * @param VersionEntry $b
	 */
	private static function newestFirst(array $a, array $b): int {
		return version_compare($b['version'], $a['version']);
	}

	/**
	 * The row for a version listed more than once: the first value of each
	 * field wins, and a later entry only fills fields still unknown.
	 *
	 * @param VersionEntry|null $known
	 * @param VersionEntry $entry
	 * @return VersionEntry
	 */
	private static function mergeEntry(?array $known, array $entry): array {
		if ($known === null) {
			return $entry;
		}
		$known['changelog'] ??= $entry['changelog'];
		$known['serverCompatible'] ??= $entry['serverCompatible'];
		$known['releasedAt'] ??= $entry['releasedAt'];

		return $known;
	}

	/**
	 * The running server's version as `major.minor.patch`, or '' when unknown.
	 */
	private function getServerVersion(): string {
		$parts = explode('.', $this->config->getSystemValueString('version'));
		$core = array_slice($parts, 0, 3);
		foreach ($core as $part) {
			if (!ctype_digit($part)) {
				return '';
			}
		}

		return implode('.', $core);
	}

	/**
	 * Whether `$serverVersion` satisfies an App Store `platformVersionSpec`
	 * such as `>=28.0.0 <=31` or `>=26.0.0 <29.0.0`. Each bound compares the
	 * server version cut to the bound's own precision, so `<=31` admits every
	 * 31.x server. Returns null when the spec or the server version cannot be
	 * read, so an unknown never reads as incompatible.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public static function satisfiesPlatformSpec(string $serverVersion, string $spec): ?bool {
		$spec = trim($spec);
		if ($serverVersion === '' || $spec === '') {
			return null;
		}
		if ($spec === '*') {
			return true;
		}

		$serverParts = explode('.', $serverVersion);
		$satisfied = true;
		foreach (preg_split('/\s+/', $spec) ?: [] as $constraint) {
			if (preg_match('/^(>=|<=|>|<|==|=)?(\d+(?:\.\d+)*)$/', $constraint, $match) !== 1) {
				return null;
			}
			$bound = $match[2];
			$precision = count(explode('.', $bound));
			$server = implode('.', array_pad(array_slice($serverParts, 0, $precision), $precision, '0'));
			$holds = match ($match[1]) {
				'>=' => version_compare($server, $bound, '>='),
				'<=' => version_compare($server, $bound, '<='),
				'>' => version_compare($server, $bound, '>'),
				'<' => version_compare($server, $bound, '<'),
				default => version_compare($server, $bound, '=='),
			};
			if (!$holds) {
				$satisfied = false;
			}
		}

		return $satisfied;
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
