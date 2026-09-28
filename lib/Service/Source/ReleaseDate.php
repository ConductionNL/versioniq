<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Normalises a source's release timestamp (the App Store's `created`, a
 * forge's `published_at`) to ISO 8601 UTC, so every version carries the same
 * `releasedAt` shape whichever source it came from.
 *
 * @spec openspec/changes/releases-version-facts/tasks.md#task-1.1
 */
final class ReleaseDate {
	private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})$/';

	/**
	 * Returns the timestamp as `YYYY-MM-DDTHH:MM:SSZ`, or null when it is
	 * absent or not an ISO 8601 date-time with a zone. A relative phrase such
	 * as "yesterday" is refused rather than resolved against today.
	 *
	 * @spec openspec/changes/releases-version-facts/tasks.md#task-1.1
	 */
	public static function normalize(mixed $value): ?string {
		if (!is_string($value) || preg_match(self::ISO_8601, trim($value)) !== 1) {
			return null;
		}

		try {
			$date = new DateTimeImmutable(trim($value));
		} catch (Exception) {
			return null;
		}

		return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}
}
