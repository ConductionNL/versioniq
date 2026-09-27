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
use OCA\Versioniq\Service\Advisory\NextcloudAdvisoryFeed;
use OCA\Versioniq\Service\Cache\ArtifactCache;
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
class InstanceSettings {
	public const KEY_APPSTORE_API_BASE = 'appstore.api_base';
	public const KEY_GITHUB_API_BASE = 'forge.github.api_base';
	public const KEY_GITHUB_WEB_BASE = 'forge.github.web_base';
	public const KEY_ADVISORY_FEED = 'advisory.feed_base';

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
	 * @return array{auditRetentionDays: int, auditRetentionMinDays: int, auditRetentionMaxDays: int, artifactCacheKeep: int, artifactCacheKeepMax: int, appStoreApiBase: string, appStoreApiDefault: string, githubApiBase: string, githubApiDefault: string, githubWebBase: string, githubWebDefault: string, advisoryFeedUrl: string, advisoryFeedDefault: string}
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
		];
	}

	/**
	 * Applies the given fields; an omitted field is left alone and a blank URL
	 * clears its override.
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @param array<string, string> $fields
	 * @return list<string> The names of the fields that were applied
	 * @throws InvalidArgumentException when any field is out of range or not an acceptable URL
	 */
	public function update(array $fields): array {
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

		$urls = [];
		foreach (self::URL_FIELDS as $field => [$key, $httpsOnly]) {
			if (isset($fields[$field])) {
				$urls[$key] = $this->url($fields[$field], $httpsOnly);
			}
		}

		foreach ($ints as $key => $value) {
			$this->config->setValueInt(Application::APP_ID, $key, $value);
		}
		foreach ($urls as $key => $value) {
			if ($value === '') {
				$this->config->deleteKey(Application::APP_ID, $key);
			} else {
				$this->config->setValueString(Application::APP_ID, $key, $value);
			}
		}

		return array_values(array_filter(
			['auditRetentionDays', 'artifactCacheKeep', ...array_keys(self::URL_FIELDS)],
			static fn (string $field): bool => isset($fields[$field]),
		));
	}

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

		$parts = parse_url($value);
		$scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
		$allowed = $httpsOnly ? ['https'] : ['https', 'http'];
		if (!is_array($parts)
			|| !in_array($scheme, $allowed, true)
			|| ($parts['host'] ?? '') === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
		) {
			throw new InvalidArgumentException($httpsOnly
				? 'Enter an https address without a username, password or query, for example https://ghe.example.org/api/v3.'
				: 'Enter an http or https address without a username, password or query.');
		}

		return $value;
	}
}
