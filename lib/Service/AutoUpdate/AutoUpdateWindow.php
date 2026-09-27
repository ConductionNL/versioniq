<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\AutoUpdate;

/**
 * Pure `HH:MM-HH:MM` maintenance-window parsing and containment logic for
 * {@see \OCA\Versioniq\BackgroundJob\AutoUpdateJob}. Supports windows that
 * cross midnight (e.g. `23:00-03:00`); see "Global kill switch and window"
 * ("Midnight-crossing window").
 *
 * @psalm-api
 */
final class AutoUpdateWindow {
	public const DEFAULT_WINDOW = '01:00-05:00';

	private const PATTERN = '/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/';

	/**
	 * Whether `$window` is a syntactically valid `HH:MM-HH:MM` window.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public static function isValid(string $window): bool {
		return preg_match(self::PATTERN, trim($window)) === 1;
	}

	/**
	 * Whether `$now` falls inside `$window`, handling windows that cross
	 * midnight; a malformed or zero-width window is treated as never
	 * inside (fail-safe — never silently "always on"); see "Global kill
	 * switch and window".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public static function isWithin(string $window, \DateTimeInterface $now): bool {
		if (preg_match(self::PATTERN, trim($window), $matches) !== 1) {
			return false;
		}

		$start = ((int)$matches[1]) * 60 + (int)$matches[2];
		$end = ((int)$matches[3]) * 60 + (int)$matches[4];
		$current = ((int)$now->format('H')) * 60 + (int)$now->format('i');

		if ($start === $end) {
			return false;
		}

		if ($start < $end) {
			return $current >= $start && $current < $end;
		}

		// Crosses midnight (e.g. 23:00-03:00).
		return $current >= $start || $current < $end;
	}

	/**
	 * Identifies the opening of the window `$now` falls in, as
	 * `<window>@<Y-m-d the window opened>`, so the job sweeps once per window
	 * even though it wakes every quarter hour (#429). After midnight inside a
	 * midnight-crossing window the window opened the day before. Changing the
	 * window changes the key, so a new window is swept on its own terms.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public static function openingKey(string $window, \DateTimeInterface $now): string {
		$window = trim($window);
		$openedOn = \DateTimeImmutable::createFromInterface($now);
		if (preg_match(self::PATTERN, $window, $matches) === 1) {
			$start = ((int)$matches[1]) * 60 + (int)$matches[2];
			$end = ((int)$matches[3]) * 60 + (int)$matches[4];
			$current = ((int)$now->format('H')) * 60 + (int)$now->format('i');
			if ($start > $end && $current < $end) {
				$openedOn = $openedOn->modify('-1 day');
			}
		}

		return $window . '@' . $openedOn->format('Y-m-d');
	}
}
