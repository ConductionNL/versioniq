<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Settings;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\BackgroundJob\PruneAuditJob;
use OCA\Versioniq\Kernel\Contracts\InstanceSettingsInterface;
use OCA\Versioniq\Service\Advisory\LocalFeedCopy;
use OCA\Versioniq\Service\Advisory\NcscAdvisoryFeed;
use OCA\Versioniq\Service\Advisory\NextcloudAdvisoryFeed;
use OCA\Versioniq\Service\Advisory\OsvClient;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lifecycle\ServerEolFeed;
use OCA\Versioniq\Service\Safety\RevocationList;
use OCA\Versioniq\Service\Safety\RevokedCertificateStore;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\IAppConfig;

/**
 * The instance settings that used to be reachable only through
 * `occ config:app:set` (#438 item 7): audit retention, how many archives the
 * artifact cache keeps, the App Store and GitHub base URLs (a store mirror,
 * GitHub Enterprise) and the advisory feed URL (an internal mirror).
 *
 * WRITES THE READERS' OWN KEYS WITH THE READERS' OWN TYPES. Each value is
 * read elsewhere (PruneAuditJob, ArtifactCache, AppStoreSource,
 * ForgeRegistry, NextcloudAdvisoryFeed); a key or type that differs from the
 * reader's saves a value nothing reads.
 *
 * An update is validated in full before anything is written, so a request
 * with one bad field changes nothing.
 *
 * @psalm-api
 */
class InstanceSettings implements InstanceSettingsInterface {
	public const KEY_APPSTORE_API_BASE = 'appstore.api_base';
	public const KEY_GITHUB_API_BASE = 'forge.github.api_base';
	public const KEY_GITHUB_WEB_BASE = 'forge.github.web_base';
	public const KEY_ADVISORY_FEED = 'advisory.feed_base';
	public const KEY_OSV_API_BASE = 'advisory.osv_api_base';
	/** How many release lines an app may fall behind; absent means off (inventory-pending-updates D3). */
	public const KEY_MAX_LINES_BEHIND = 'update.max_lines_behind';
	/** Whether apps an update left disabled are enabled again when they run (audit-external-changes). */
	public const KEY_REACTIVATE_AUTO = 'reactivate.auto';
	/** Whether Discover also searches public GitHub; read by GithubSearchDiscovery (sources-trust-audit-and-offline-feed). */
	public const KEY_GITHUB_SEARCH = 'discovery.github_search_enabled';
	/** Whether an archive the App Store does not sign may be installed from an upload; off unless an admin turns it on (install-manual-upload D4). */
	public const KEY_ALLOW_UNSIGNED_UPLOAD = 'install.allow_unsigned_upload';

	/**
	 * Whether an update through Versioniq tells the app's users, as the
	 * platform's own updates do (releases-notes-reach D1). On by default.
	 */
	public const KEY_NOTIFY_USERS_ON_UPDATE = InstallerService::KEY_NOTIFY_USERS_ON_UPDATE;

	public const MAX_LINES_BEHIND = 10;

	/** Days a version must run on the earlier stage before it may be promoted; 0 is off. */
	public const KEY_PROMOTION_MIN_DAYS = 'promotion.min_days';

	public const MAX_PROMOTION_MIN_DAYS = 90;

	/** Ten years: longer is a history nobody reads, and the table keeps growing. */
	public const MAX_RETENTION_DAYS = 3650;

	/** Archives per app; each is a full release tarball. 0 turns the cache off. */
	public const MAX_CACHE_KEEP = 20;

	/** Field name to [config key, https only]. */
	private const URL_FIELDS = [
		'appStoreApiBase' => [self::KEY_APPSTORE_API_BASE, false],
		// Tokens are sent to GitHub, so only https.
		'githubApiBase' => [self::KEY_GITHUB_API_BASE, true],
		'githubWebBase' => [self::KEY_GITHUB_WEB_BASE, true],
		'advisoryFeedUrl' => [self::KEY_ADVISORY_FEED, false],
		// The NCSC-NL CSAF directory (advisories-ncsc-feed), https only.
		'ncscFeedUrl' => [NcscAdvisoryFeed::CONFIG_BASE, true],
		// Library names leave the server, so only https (advisories-bundled-libraries).
		'osvApiBase' => [self::KEY_OSV_API_BASE, true],
	];

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * The current values, the overrides as stored ('' when unset) and the
	 * defaults they fall back to, so the page can show both.
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @return array{auditRetentionDays: int, auditRetentionMinDays: int, auditRetentionMaxDays: int, artifactCacheKeep: int, artifactCacheKeepMax: int, appStoreApiBase: string, appStoreApiDefault: string, githubApiBase: string, githubApiDefault: string, githubWebBase: string, githubWebDefault: string, advisoryFeedUrl: string, advisoryFeedDefault: string, eolFeedUrl: string, eolFeedOff: bool, eolFeedDefault: string, ncscFeedUrl: string, ncscFeedDefault: string, osvApiBase: string, osvApiDefault: string, maxLinesBehind: ?int, maxLinesBehindMax: int, reactivateAuto: bool, githubSearchEnabled: bool, advisoryFeedSource: string, advisoryFeedCopiedAt: ?int, advisoryFeedCopyMaxAgeDays: int, notifyUsersOnUpdate: bool, allowUnsignedUpload: bool, crlUrl: string, crlUrlDefault: string, disableRevoked: bool}
	 */
	public function read(): array {
		$retention = $this->config->getValueInt(Application::APP_ID, PruneAuditJob::CONFIG_KEY_RETENTION_DAYS, PruneAuditJob::DEFAULT_RETENTION_DAYS);

		return [
			// The effective value: PruneAuditJob clamps to the floor.
			'auditRetentionDays' => max(PruneAuditJob::MINIMUM_RETENTION_DAYS, $retention),
			'auditRetentionMinDays' => PruneAuditJob::MINIMUM_RETENTION_DAYS,
			'auditRetentionMaxDays' => self::MAX_RETENTION_DAYS,
			'artifactCacheKeep' => $this->config->getValueInt(Application::APP_ID, ArtifactCache::CONFIG_KEEP, ArtifactCache::DEFAULT_KEEP),
			'artifactCacheKeepMax' => self::MAX_CACHE_KEEP,
			'appStoreApiBase' => $this->stored(self::KEY_APPSTORE_API_BASE),
			'appStoreApiDefault' => AppStoreSource::DEFAULT_API_BASE,
			'githubApiBase' => $this->stored(self::KEY_GITHUB_API_BASE),
			'githubApiDefault' => ForgeRegistry::GITHUB_API_DEFAULT,
			'githubWebBase' => $this->stored(self::KEY_GITHUB_WEB_BASE),
			'githubWebDefault' => ForgeRegistry::GITHUB_WEB_DEFAULT,
			'advisoryFeedUrl' => $this->stored(self::KEY_ADVISORY_FEED),
			'advisoryFeedDefault' => NextcloudAdvisoryFeed::DEFAULT_FEED_URL,
			// '' with the key present means the admin switched the feed off.
			'eolFeedUrl' => $this->stored(ServerEolFeed::KEY_FEED),
			'eolFeedOff' => $this->config->hasKey(Application::APP_ID, ServerEolFeed::KEY_FEED) && $this->stored(ServerEolFeed::KEY_FEED) === '',
			'eolFeedDefault' => ServerEolFeed::DEFAULT_FEED_URL,
			'ncscFeedUrl' => $this->stored(NcscAdvisoryFeed::CONFIG_BASE),
			'ncscFeedDefault' => NcscAdvisoryFeed::DEFAULT_BASE,
			'osvApiBase' => $this->stored(self::KEY_OSV_API_BASE),
			'osvApiDefault' => OsvClient::DEFAULT_API_BASE,
			'maxLinesBehind' => $this->maxLinesBehind(),
			'maxLinesBehindMax' => self::MAX_LINES_BEHIND,
			'promotionMinDays' => $this->promotionMinDays(),
			'promotionMinDaysMax' => self::MAX_PROMOTION_MIN_DAYS,
			'reactivateAuto' => $this->config->getValueBool(Application::APP_ID, self::KEY_REACTIVATE_AUTO, false),
			'githubSearchEnabled' => $this->config->getValueString(Application::APP_ID, self::KEY_GITHUB_SEARCH, 'false') === 'true',
			...$this->feedSource(),
			'allowUnsignedUpload' => $this->allowsUnsignedUpload(),
			'notifyUsersOnUpdate' => $this->config->getValueBool(Application::APP_ID, self::KEY_NOTIFY_USERS_ON_UPDATE, true),
			...$this->safety(),
		];
	}

	/**
	 * The revocation list address in effect ('' while fetching is off), its
	 * default, and whether apps with a revoked certificate are disabled.
	 *
	 * @return array{crlUrl: string, crlUrlDefault: string, disableRevoked: bool}
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	private function safety(): array {
		return [
			'crlUrl' => $this->config->hasKey(Application::APP_ID, RevocationList::KEY_URL) ? $this->stored(RevocationList::KEY_URL) : RevocationList::DEFAULT_URL,
			'crlUrlDefault' => RevocationList::DEFAULT_URL,
			'disableRevoked' => $this->config->getValueBool(Application::APP_ID, RevokedCertificateStore::KEY_DISABLE, false),
		];
	}

	/**
	 * The sent revocation list address and disable switch, validated: the
	 * address as stored ('' switches fetching off), the switch as a bool.
	 *
	 * @param array<string, string> $fields
	 * @return array{crlUrl?: string, disableRevoked?: bool}
	 * @throws InvalidArgumentException when the address or the switch is not acceptable
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	private function validatedSafety(array $fields): array {
		$safety = [];
		if (isset($fields['crlUrl'])) {
			$safety['crlUrl'] = $this->url($fields['crlUrl'], false);
		}
		$disable = self::validatedSwitch($fields, 'disableRevoked', 'Disabling apps with a revoked certificate');
		if ($disable !== null) {
			$safety['disableRevoked'] = $disable === 'true';
		}

		return $safety;
	}

	/**
	 * Stores what {@see validatedSafety()} returned. An empty address is kept:
	 * it switches fetching off instead of falling back to the default.
	 *
	 * @param array{crlUrl?: string, disableRevoked?: bool} $safety
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	private function storeSafety(array $safety): void {
		if (isset($safety['crlUrl']) && $safety['crlUrl'] === RevocationList::DEFAULT_URL) {
			// The default is not stored, so a later default applies.
			$this->config->deleteKey(Application::APP_ID, RevocationList::KEY_URL);
		} elseif (isset($safety['crlUrl'])) {
			$this->config->setValueString(Application::APP_ID, RevocationList::KEY_URL, $safety['crlUrl']);
		}
		if (isset($safety['disableRevoked'])) {
			$this->config->setValueBool(Application::APP_ID, RevokedCertificateStore::KEY_DISABLE, $safety['disableRevoked']);
		}
	}

	/**
	 * Applies the given fields; an omitted field is left alone and a blank URL
	 * clears its override.
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 * @param array<string, string> $fields
	 * @return list<string> The names of the fields that were applied
	 * @throws InvalidArgumentException when any field is out of range or not an acceptable URL
	 */
	public function update(array $fields): array {
		// Every field is validated before anything is written, so a refusal
		// leaves the stored settings as they were.
		$ints = $this->validatedInts($fields);
		$cleared = [...$this->validatedLagLimit($fields), ...$this->validatedWaitDays($fields), ...$this->validatedUrls($fields)];
		$reactivate = self::validatedSwitch($fields, 'reactivateAuto', 'Automatic re-enabling');
		$githubSearch = self::validatedSwitch($fields, 'githubSearchEnabled', 'GitHub search');
		$notify = self::validatedSwitch($fields, 'notifyUsersOnUpdate', 'Telling users about updates');
		$feedSource = self::validatedFeedSource($fields);
		$unsigned = self::validatedSwitch($fields, 'allowUnsignedUpload', 'Allow unsigned uploads');
		$eolFeed = isset($fields['eolFeedUrl']) ? $this->url($fields['eolFeedUrl'], false) : null;
		$safety = $this->validatedSafety($fields);

		$this->storeInts($ints);
		$this->storeSafety($safety);
		if ($githubSearch !== null) {
			$this->config->setValueString(Application::APP_ID, self::KEY_GITHUB_SEARCH, $githubSearch);
		}
		if ($notify !== null) {
			$this->config->setValueBool(Application::APP_ID, self::KEY_NOTIFY_USERS_ON_UPDATE, $notify === 'true');
		}
		if ($feedSource !== null) {
			$this->config->setValueString(Application::APP_ID, NextcloudAdvisoryFeed::CONFIG_FEED_SOURCE, $feedSource);
		}
		if ($unsigned !== null) {
			$this->config->setValueBool(Application::APP_ID, self::KEY_ALLOW_UNSIGNED_UPLOAD, $unsigned === 'true');
		}
		foreach ($cleared as $key => $value) {
			$this->storeOrClear($key, $value);
		}
		if ($reactivate !== null) {
			$this->config->setValueBool(Application::APP_ID, self::KEY_REACTIVATE_AUTO, $reactivate === 'true');
		}
		if ($eolFeed !== null) {
			// Unlike the other addresses, an empty end-of-life feed is kept:
			// it switches the warnings off instead of falling back to the default.
			$this->config->setValueString(Application::APP_ID, ServerEolFeed::KEY_FEED, $eolFeed);
		}

		return self::appliedFields($fields);
	}

	/**
	 * The names of the known fields present in $fields, in form order.
	 *
	 * @param array<string, string> $fields
	 * @return list<string>
	 */
	private static function appliedFields(array $fields): array {
		return array_values(array_filter(
			['auditRetentionDays', 'artifactCacheKeep', 'maxLinesBehind', 'promotionMinDays', 'notifyUsersOnUpdate', ...array_keys(self::URL_FIELDS), 'reactivateAuto', 'githubSearchEnabled', 'advisoryFeedSource', 'eolFeedUrl', 'allowUnsignedUpload', 'crlUrl', 'disableRevoked'],
			static fn (string $field): bool => isset($fields[$field]),
		));
	}

	/**
	 * A sent on/off switch as it is stored (`true` or `false`), null when not sent.
	 *
	 * @param array<string, string> $fields The sent fields.
	 * @throws InvalidArgumentException When the value is not on or off.
	 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/app-discovery/spec.md
	 */
	private static function validatedSwitch(array $fields, string $field, string $label): ?string {
		if (!isset($fields[$field])) {
			return null;
		}

		return match (strtolower(trim($fields[$field]))) {
			'true', '1' => 'true',
			'false', '0' => 'false',
			default => throw new InvalidArgumentException($label . ' must be on or off.'),
		};
	}

	/**
	 * Where advisories are read from, and the state of the imported copy: when
	 * it was imported and the age past which the page warns about it.
	 *
	 * @return array{advisoryFeedSource: string, advisoryFeedCopiedAt: ?int, advisoryFeedCopyMaxAgeDays: int}
	 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
	 */
	private function feedSource(): array {
		$copiedAt = $this->config->getValueInt(Application::APP_ID, LocalFeedCopy::CONFIG_COPIED_AT, 0);

		return [
			'advisoryFeedSource' => $this->config->getValueString(Application::APP_ID, NextcloudAdvisoryFeed::CONFIG_FEED_SOURCE, NextcloudAdvisoryFeed::SOURCE_REMOTE) === NextcloudAdvisoryFeed::SOURCE_LOCAL
				? NextcloudAdvisoryFeed::SOURCE_LOCAL
				: NextcloudAdvisoryFeed::SOURCE_REMOTE,
			'advisoryFeedCopiedAt' => $copiedAt > 0 ? $copiedAt : null,
			'advisoryFeedCopyMaxAgeDays' => LocalFeedCopy::MAX_AGE_DAYS,
		];
	}

	/**
	 * A sent feed source as it is stored (`remote` or `local`), null when not sent.
	 *
	 * @param array<string, string> $fields The sent fields.
	 * @throws InvalidArgumentException When the value is neither.
	 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
	 */
	private static function validatedFeedSource(array $fields): ?string {
		if (!isset($fields['advisoryFeedSource'])) {
			return null;
		}

		return match (strtolower(trim($fields['advisoryFeedSource']))) {
			NextcloudAdvisoryFeed::SOURCE_REMOTE => NextcloudAdvisoryFeed::SOURCE_REMOTE,
			NextcloudAdvisoryFeed::SOURCE_LOCAL => NextcloudAdvisoryFeed::SOURCE_LOCAL,
			default => throw new InvalidArgumentException('The advisory feed source must be remote or local.'),
		};
	}

	/**
	 * @param array<string, int> $ints
	 */
	private function storeInts(array $ints): void {
		foreach ($ints as $key => $value) {
			$this->config->setValueInt(Application::APP_ID, $key, $value);
		}
	}

	/**
	 * The integer settings among $fields, by config key.
	 *
	 * @param array<string, string> $fields
	 * @return array<string, int>
	 * @throws InvalidArgumentException when a value is out of range
	 */
	private function validatedInts(array $fields): array {
		$ints = [];
		if (isset($fields['auditRetentionDays'])) {
			$ints[PruneAuditJob::CONFIG_KEY_RETENTION_DAYS] = $this->intInRange(
				$fields['auditRetentionDays'],
				PruneAuditJob::MINIMUM_RETENTION_DAYS,
				self::MAX_RETENTION_DAYS,
				'The audit retention',
			);
		}
		if (isset($fields['artifactCacheKeep'])) {
			$ints[ArtifactCache::CONFIG_KEEP] = $this->intInRange($fields['artifactCacheKeep'], 0, self::MAX_CACHE_KEEP, 'The number of cached versions');
		}

		return $ints;
	}

	/**
	 * Whether an admin allowed installing an archive upload the App Store does not sign.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.3
	 */
	public function allowsUnsignedUpload(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_ALLOW_UNSIGNED_UPLOAD, false);
	}

	/**
	 * The lag limit to store by config key, '' to clear it; empty when it is not given.
	 *
	 * @param array<string, string> $fields
	 * @return array<string, string>
	 * @throws InvalidArgumentException when the value is out of range
	 */
	private function validatedLagLimit(array $fields): array {
		if (!isset($fields['maxLinesBehind'])) {
			return [];
		}

		return [self::KEY_MAX_LINES_BEHIND => trim($fields['maxLinesBehind']) === ''
			? ''
			: (string)$this->intInRange($fields['maxLinesBehind'], 0, self::MAX_LINES_BEHIND, 'The number of releases an app may fall behind')];
	}

	/**
	 * The waiting period among $fields, by config key; '' (for 0) switches it off.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
	 * @param array<string, string> $fields
	 * @return array<string, string>
	 * @throws InvalidArgumentException when the value is outside 0 to 90
	 */
	private function validatedWaitDays(array $fields): array {
		if (!isset($fields['promotionMinDays'])) {
			return [];
		}

		$days = $this->intInRange($fields['promotionMinDays'], 0, self::MAX_PROMOTION_MIN_DAYS, 'The waiting period');

		return [self::KEY_PROMOTION_MIN_DAYS => $days === 0 ? '' : (string)$days];
	}

	/**
	 * The URL overrides among $fields, by config key; '' clears one.
	 *
	 * @param array<string, string> $fields
	 * @return array<string, string>
	 * @throws InvalidArgumentException when a value is not an acceptable URL
	 */
	private function validatedUrls(array $fields): array {
		$urls = [];
		foreach (self::URL_FIELDS as $field => [$key, $httpsOnly]) {
			if (isset($fields[$field])) {
				$urls[$key] = $this->url($fields[$field], $httpsOnly);
			}
		}

		return $urls;
	}

	/**
	 * Stores $value under $key, or deletes the key when $value is ''.
	 */
	private function storeOrClear(string $key, string $value): void {
		if ($value === '') {
			$this->config->deleteKey(Application::APP_ID, $key);
		} else {
			$this->config->setValueString(Application::APP_ID, $key, $value);
		}
	}

	/**
	 * The admin's lag limit in release lines, or null when the check is off.
	 *
	 * @spec openspec/specs/pending-updates/spec.md#requirement-an-admin-sets-how-far-an-app-may-fall-behind
	 */
	public function maxLinesBehind(): ?int {
		$raw = $this->stored(self::KEY_MAX_LINES_BEHIND);
		if (preg_match('/^\d+$/', $raw) !== 1) {
			return null;
		}

		return min((int)$raw, self::MAX_LINES_BEHIND);
	}

	/**
	 * How many days a version must run on the earlier stage before it may be
	 * promoted, 0 to 90; 0 (also for an unset or damaged value) is off.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
	 */
	public function promotionMinDays(): int {
		$raw = $this->stored(self::KEY_PROMOTION_MIN_DAYS);
		if (preg_match('/^\d+$/', $raw) !== 1) {
			return 0;
		}

		return min((int)$raw, self::MAX_PROMOTION_MIN_DAYS);
	}

	/**
	 * Store the waiting period; 0 switches it off.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
	 * @throws InvalidArgumentException when the value is outside 0 to 90
	 */
	public function setPromotionMinDays(int $days): void {
		if ($days < 0 || $days > self::MAX_PROMOTION_MIN_DAYS) {
			throw new InvalidArgumentException(sprintf('The waiting period must be between 0 and %d days.', self::MAX_PROMOTION_MIN_DAYS));
		}

		$this->storeOrClear(self::KEY_PROMOTION_MIN_DAYS, $days === 0 ? '' : (string)$days);
	}

	/** Address parts a configured base URL must not carry. */
	private const REFUSED_ADDRESS_PARTS = ['user' => true, 'pass' => true, 'query' => true, 'fragment' => true];

	private function stored(string $key): string {
		return trim($this->config->getValueString(Application::APP_ID, $key, ''));
	}

	private function intInRange(string $raw, int $min, int $max, string $label): int {
		$raw = trim($raw);
		if (preg_match('/^\d+$/', $raw) !== 1 || (int)$raw < $min || (int)$raw > $max) {
			throw new InvalidArgumentException(sprintf('%s must be a whole number between %d and %d.', $label, $min, $max));
		}

		return (int)$raw;
	}

	private function url(string $raw, bool $httpsOnly): string {
		$value = rtrim(trim($raw), '/');
		if ($value === '') {
			return '';
		}

		if (!self::isPlainAddress(parse_url($value), $httpsOnly ? ['https'] : ['https', 'http'])) {
			throw new InvalidArgumentException($httpsOnly
				? 'Enter an https address without a username, password or query, for example https://ghe.example.org/api/v3.'
				: 'Enter an http or https address without a username, password or query.');
		}

		return $value;
	}

	/**
	 * Whether parsed address parts have an allowed scheme and a host, and no
	 * username, password, query or fragment.
	 *
	 * @param array<string, int|string>|false $parts What parse_url() returned.
	 * @param list<string> $allowedSchemes
	 */
	private static function isPlainAddress(array|false $parts, array $allowedSchemes): bool {
		return is_array($parts)
			&& in_array($parts['scheme'] ?? '', $allowedSchemes, true)
			&& ($parts['host'] ?? '') !== ''
			&& array_intersect_key($parts, self::REFUSED_ADDRESS_PARTS) === [];
	}
}
