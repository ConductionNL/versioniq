<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Settings\AppDataFiles;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;

/**
 * A local copy of the advisory feed for an instance without internet access
 * (sources-trust-audit-and-offline-feed). An admin imports a file in the
 * format the feed address serves, a JSON list of advisory records; it is
 * validated, kept in Versioniq's app data as `advisories/feed.json`, and read
 * by {@see NextcloudAdvisoryFeed} when the feed source is `local`.
 *
 * A file that does not parse is refused before anything is written, so the
 * previous copy stays in use.
 *
 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
 */
class LocalFeedCopy {
	public const FOLDER = 'advisories';
	public const FILE = 'feed.json';

	/** When the copy in app data was imported, a Unix time; absent while there is none. */
	public const CONFIG_COPIED_AT = 'advisory.feed_copy_at';

	/** How many advisory records the copy holds. */
	public const CONFIG_RECORDS = 'advisory.feed_copy_records';

	/** A copy older than this is shown with a warning. */
	public const MAX_AGE_DAYS = 30;

	public function __construct(
		private AppDataFiles $appData,
		private IAppConfig $config,
	) {
	}

	/**
	 * Validates a feed file and, when it parses, stores it as the local copy.
	 *
	 * @param string $content The file as uploaded.
	 * @param int $now The import time, a Unix time.
	 * @return array{copiedAt: int, records: int} The state of the new copy.
	 * @throws InvalidArgumentException When the file is not a valid feed; nothing is written.
	 *
	 * @spec openspec/changes/sources-trust-audit-and-offline-feed/tasks.md
	 */
	public function import(string $content, int $now): array {
		$records = self::validated($content);

		$this->appData->write(self::FOLDER . '/' . self::FILE, json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

		$this->config->setValueInt(Application::APP_ID, self::CONFIG_COPIED_AT, $now);
		$this->config->setValueInt(Application::APP_ID, self::CONFIG_RECORDS, count($records));

		return ['copiedAt' => $now, 'records' => count($records)];
	}

	/**
	 * The advisory records of the copy, or null when none was imported or it
	 * can no longer be read.
	 *
	 * @return list<array<array-key, mixed>>|null
	 *
	 * @spec openspec/changes/sources-trust-audit-and-offline-feed/tasks.md
	 */
	public function records(): ?array {
		try {
			$content = $this->appData->read(self::FOLDER . '/' . self::FILE);
			return self::validated($content);
		} catch (NotFoundException|NotPermittedException|InvalidArgumentException) {
			return null;
		}
	}

	/**
	 * When the copy was imported, or null when there is none.
	 *
	 * @spec openspec/changes/sources-trust-audit-and-offline-feed/tasks.md
	 */
	public function copiedAt(): ?int {
		$copiedAt = $this->config->getValueInt(Application::APP_ID, self::CONFIG_COPIED_AT, 0);

		return $copiedAt > 0 ? $copiedAt : null;
	}

	/**
	 * Whether a copy imported at $copiedAt is older than 30 days at $now. No
	 * copy is not stale; it is missing.
	 *
	 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
	 */
	public static function isStale(?int $copiedAt, int $now): bool {
		return $copiedAt !== null && ($now - $copiedAt) > self::MAX_AGE_DAYS * 86400;
	}

	/**
	 * The records of a feed file: a non-empty JSON list whose every entry is
	 * an advisory record with a `ghsa_id`.
	 *
	 * @return list<array<array-key, mixed>>
	 * @throws InvalidArgumentException With the reason the file is refused.
	 *
	 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
	 */
	public static function validated(string $content): array {
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			throw new InvalidArgumentException('The file is not valid JSON: ' . $error->getMessage());
		}
		if (!is_array($decoded) || !array_is_list($decoded) || $decoded === []) {
			throw new InvalidArgumentException('The file must hold a list of advisories, as the feed address serves it.');
		}

		return array_map(self::validatedRecord(...), $decoded);
	}

	/**
	 * One advisory record of a feed file.
	 *
	 * @return array<array-key, mixed>
	 * @throws InvalidArgumentException When the entry is not an advisory record.
	 */
	private static function validatedRecord(mixed $record): array {
		if (!is_array($record) || !is_string($record['ghsa_id'] ?? null) || $record['ghsa_id'] === '') {
			throw new InvalidArgumentException('Every entry in the file must be an advisory with a ghsa_id.');
		}

		return $record;
	}
}
