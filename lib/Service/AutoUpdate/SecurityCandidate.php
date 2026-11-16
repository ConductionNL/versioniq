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
	 * @param int|null $checkedAt Unix time of the last completed sweep, null for never.
	 * @param int $now Unix time now.
	 * @param int $intervalSeconds The advisory sweep interval.
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
	 * @param PlanSubject $app The app: its installed version and the versions its source lists.
	 * @param PlanRun $run The run: the advisory snapshot and whether it is fresh (see isFresh()).
	 *
	 * @return array{version: ?string, reason: ?string} Exactly one of the two is set.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
	 */
	public static function decide(PlanSubject $app, PlanRun $run): array {
		$reason = self::reasonAgainst(app: $app, run: $run);
		if ($reason !== null) {
			return ['version' => null, 'reason' => $reason];
		}

		return ['version' => (string)($run->rowOf($app->appId)['recommendedVersion'] ?? ''), 'reason' => null];
	}

	/**
	 * The recommended fix of an affected app when the snapshot is fresh and the
	 * app's source lists it; null otherwise. No version rules: a level's own
	 * selection decides whether it may install.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
	 */
	public static function listedFix(PlanSubject $app, PlanRun $run): ?string {
		$row = $run->rowOf($app->appId);
		if (!$run->fresh || !self::isAffected(row: $row)) {
			return null;
		}

		return self::listed(app: $app, version: $row['recommendedVersion'] ?? null);
	}

	/**
	 * Why the app gets no security install: a stale snapshot, a refusal by the
	 * snapshot row, or a fix the source does not list; null when it may install.
	 */
	private static function reasonAgainst(PlanSubject $app, PlanRun $run): ?string {
		if (!$run->fresh) {
			return self::REASON_STALE;
		}

		$row = $run->rowOf($app->appId);
		$refusal = self::refusal(installed: $app->installedVersion, row: $row);
		if ($refusal !== null) {
			return $refusal;
		}

		return self::listed(app: $app, version: $row['recommendedVersion'] ?? null) === null ? self::REASON_NOT_IN_SOURCE : null;
	}

	/**
	 * Why a fresh snapshot gives the app no security install, before the
	 * source is asked; null when the recommended fix may install.
	 *
	 * @param string $installed The installed version.
	 * @param array<string, mixed>|null $row The app's snapshot row.
	 */
	private static function refusal(string $installed, ?array $row): ?string {
		if (!self::isAffected(row: $row)) {
			return self::REASON_NOT_AFFECTED;
		}

		$recommended = $row['recommendedVersion'] ?? null;
		if (!is_string($recommended) || !self::isNewer(recommended: $recommended, installed: $installed)) {
			return self::REASON_NO_FIX;
		}

		return self::major($recommended) !== self::major($installed) ? self::REASON_NEEDS_MAJOR : null;
	}

	/**
	 * Whether the snapshot row says the installed version is affected.
	 *
	 * @param array<string, mixed>|null $row The app's snapshot row.
	 */
	private static function isAffected(?array $row): bool {
		return ($row['state'] ?? null) === AdvisoryService::STATE_VULNERABLE;
	}

	/**
	 * Whether the recommended version is a version above the installed one.
	 */
	private static function isNewer(string $recommended, string $installed): bool {
		return $recommended !== '' && version_compare($recommended, $installed, '>');
	}

	/**
	 * The version when it is a string the app's source lists, else null.
	 */
	private static function listed(PlanSubject $app, mixed $version): ?string {
		return is_string($version) && in_array($version, $app->available, true) ? $version : null;
	}

	/** The major part of a version: everything before its first dot. */
	private static function major(string $version): string {
		return explode('.', ltrim($version, 'vV'), 2)[0];
	}
}
