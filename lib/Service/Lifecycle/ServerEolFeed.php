<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Lifecycle;

use OCA\Versioniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The end-of-life date of each Nextcloud server major, read from a feed.
 *
 * The feed lists `{cycle, eol}` per major, as endoflife.date publishes it. Its
 * address is an instance setting: unset reads the public feed, an empty value
 * switches the feed off (an offline instance, or an admin who does not want
 * it). A successful answer is kept for a day in app config. When the feed is
 * off or cannot be read, every answer is "unknown" (null): nothing is ever
 * called out of support on a guess.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
 */
class ServerEolFeed {
	public const DEFAULT_FEED_URL = 'https://endoflife.date/api/nextcloud.json';

	/** The feed address; absent reads the default, '' switches it off. */
	public const KEY_FEED = 'lifecycle.eol_feed';

	/** The last good answer, `{fetchedAt, majors}`. */
	public const KEY_CACHE = 'lifecycle.eol_cache';

	public const CACHE_SECONDS = 86400;

	private const FETCH_TIMEOUT_SECONDS = 15;

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $config,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The feed address, or null when the admin switched the feed off.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
	 */
	public function feedUrl(): ?string {
		if ($this->config->hasKey(Application::APP_ID, self::KEY_FEED) === false) {
			return self::DEFAULT_FEED_URL;
		}

		$url = trim($this->config->getValueString(Application::APP_ID, self::KEY_FEED, ''));

		return $url === '' ? null : $url;
	}

	/**
	 * The end-of-life date (Y-m-d) of each major the feed lists, '' for a major
	 * without a date; null when the feed is off or unreadable.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
	 * @return array<int, string>|null
	 */
	public function majors(): ?array {
		$url = $this->feedUrl();
		if ($url === null) {
			return null;
		}

		$cached = $this->cached($url);
		if ($cached !== null) {
			return $cached;
		}

		$majors = $this->fetch($url);
		if ($majors !== null) {
			$this->config->setValueString(Application::APP_ID, self::KEY_CACHE, (string)json_encode([
				'url' => $url,
				'fetchedAt' => $this->timeFactory->getTime(),
				'majors' => $majors,
			]));
		}

		return $majors;
	}

	/**
	 * The end-of-life date of one major, or null when it is not known.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
	 */
	public function eolDate(int $major): ?string {
		$date = $this->majors()[$major] ?? '';

		return $date === '' ? null : $date;
	}

	/**
	 * The majors still maintained today, or null when that is not known.
	 *
	 * A major is maintained until its end-of-life date has passed; one the feed
	 * lists without a date is maintained.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
	 * @return list<int>|null
	 */
	public function maintainedMajors(): ?array {
		$majors = $this->majors();
		if ($majors === null) {
			return null;
		}

		$today = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d');
		$maintained = [];
		foreach ($majors as $major => $eol) {
			if ($eol === '' || $eol > $today) {
				$maintained[] = $major;
			}
		}

		return $maintained;
	}

	/**
	 * Whether an app is out of support: none of the server majors its releases
	 * support is still maintained. Null when that cannot be told.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
	 * @param list<int> $supportedMajors The server majors the app's listed releases support.
	 */
	public function endOfSupport(array $supportedMajors): ?bool {
		$maintained = $this->maintainedMajors();
		if ($maintained === null || $maintained === [] || $supportedMajors === []) {
			return null;
		}

		return array_intersect($supportedMajors, $maintained) === [];
	}

	/**
	 * The cached answer for this address while it is younger than a day.
	 *
	 * @return array<int, string>|null
	 */
	private function cached(string $url): ?array {
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY_CACHE, '');
		/** @var mixed $cache */
		$cache = json_decode($raw, true);
		if (!is_array($cache) || ($cache['url'] ?? null) !== $url || !is_array($cache['majors'] ?? null)) {
			return null;
		}

		$age = $this->timeFactory->getTime() - (int)($cache['fetchedAt'] ?? 0);
		if ($age < 0 || $age >= self::CACHE_SECONDS) {
			return null;
		}

		$majors = [];
		foreach ($cache['majors'] as $major => $eol) {
			$majors[(int)$major] = (string)$eol;
		}

		return $majors;
	}

	/**
	 * The majors the feed answers, or null when it cannot be read.
	 *
	 * @return array<int, string>|null
	 */
	private function fetch(string $url): ?array {
		try {
			$response = $this->clientService->newClient()->get($url, ['timeout' => self::FETCH_TIMEOUT_SECONDS]);
			if ($response->getStatusCode() !== 200) {
				throw new \RuntimeException('HTTP ' . $response->getStatusCode());
			}
			/** @var mixed $rows */
			$rows = json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR);
		} catch (Throwable $error) {
			$this->logger->warning('ServerEolFeed: the end-of-life feed could not be read', ['message' => $error->getMessage()]);

			return null;
		}

		return is_array($rows) ? self::parse($rows) : null;
	}

	/**
	 * The `{cycle, eol}` rows as major => date. A row whose cycle is not a
	 * whole major is skipped; `eol: false` is a major without a date.
	 *
	 * @param array<array-key, mixed> $rows
	 * @return array<int, string>
	 */
	private static function parse(array $rows): array {
		$majors = [];
		foreach ($rows as $row) {
			if (!is_array($row) || preg_match('/^\d+$/', (string)($row['cycle'] ?? '')) !== 1) {
				continue;
			}
			$majors[(int)$row['cycle']] = self::eolOf($row['eol'] ?? false);
		}
		ksort($majors);

		return $majors;
	}

	/**
	 * A row's end-of-life date: the date itself, '' for `false` (no date yet),
	 * and the epoch for `true` (ended, the feed gives no date).
	 */
	private static function eolOf(mixed $eol): string {
		if ($eol === true) {
			return '1970-01-01';
		}

		return (is_string($eol) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eol) === 1) ? $eol : '';
	}
}
