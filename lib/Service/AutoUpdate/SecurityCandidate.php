<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\Service\Advisory\AdvisoryService;

/**
 * Decides the security fix an app on the `security` level gets, from the
 * last advisory snapshot (autoupdate-security-first D1 and D2).
 *
 * An app gets the version the advisory check recommends only when that
 * check says its installed version is affected, the recommendation is newer
 * and in the same major, the app's source lists it, and the snapshot is
 * fresh. Otherwise nothing installs and the decision names why, so "no
 * install" is never silent.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
final class SecurityCandidate {
	/** The installed version is not affected by any active advisory. */
	public const REASON_NOT_AFFECTED = 'not_affected';

	/** The installed version is affected but no fixed version is known. */
	public const REASON_NO_FIX = 'no_fix';

	/** The fix is in a newer major version, which this level never installs. */
	public const REASON_NEEDS_MAJOR = 'needs_major';

	/** The fixed version is not offered by the app's source. */
	public const REASON_NOT_IN_SOURCE = 'not_in_source';

	/** Advisories were not checked recently, so security fixes wait. */
	public const REASON_STALE = 'stale';

	/** A snapshot older than this many advisory intervals is stale (D2). */
	public const STALE_AFTER_INTERVALS = 2;

	/**
	 * Whether a snapshot taken at `$checkedAt` may still decide installs:
	 * it exists and is at most two advisory intervals old.
	 *
	 * @param int|null $checkedAt       Unix time of the last completed sweep, null for never.
	 * @param int      $now             Unix time now.
	 * @param int      $intervalSeconds The advisory sweep interval.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
	 */
	public static function isFresh(?int $checkedAt, int $now, int $intervalSeconds): bool {
		if ($checkedAt === null || $checkedAt <= 0) {
			return false;
		}

		return ($now - $checkedAt) <= self::STALE_AFTER_INTERVALS * max(1, $intervalSeconds);
	}

	/**
	 * The fix to install for one app, or the reason there is none.
	 *
	 * @param string                    $installed The installed version.
	 * @param array<string, mixed>|null $row       The app's row in the advisory snapshot, null when it has none.
	 * @param list<string>              $available The versions the app's source lists.
	 * @param bool                      $fresh     Whether the snapshot is fresh (see isFresh()).
	 *
	 * @return array{version: ?string, reason: ?string} Exactly one of the two is set.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
	 */
	public static function decide(string $installed, ?array $row, array $available, bool $fresh): array {
		$reason = self::refusal(installed: $installed, row: $row, available: $available, fresh: $fresh);
		if ($reason !== null) {
			return ['version' => null, 'reason' => $reason];
		}

		return ['version' => (string)$row['recommendedVersion'], 'reason' => null];
	}

	/**
	 * Why the app gets no security install, or null when it does.
	 *
	 * @param string                    $installed The installed version.
	 * @param array<string, mixed>|null $row       The app's snapshot row.
	 * @param list<string>              $available The versions the source lists.
	 * @param bool                      $fresh     Whether the snapshot is fresh.
	 */
	private static function refusal(string $installed, ?array $row, array $available, bool $fresh): ?string {
		if ($fresh === false) {
			return self::REASON_STALE;
		}

		if (($row['state'] ?? null) !== AdvisoryService::STATE_VULNERABLE) {
			return self::REASON_NOT_AFFECTED;
		}

		$recommended = $row['recommendedVersion'] ?? null;
		if (is_string($recommended) === false || $recommended === '' || version_compare($recommended, $installed, '>') === false) {
			return self::REASON_NO_FIX;
		}

		if (self::major($recommended) !== self::major($installed)) {
			return self::REASON_NEEDS_MAJOR;
		}

		if (in_array($recommended, $available, true) === false) {
			return self::REASON_NOT_IN_SOURCE;
		}

		return null;
	}

	/** The major part of a version: everything before its first dot. */
	private static function major(string $version): string {
		return explode('.', ltrim($version, 'vV'), 2)[0];
	}
}
