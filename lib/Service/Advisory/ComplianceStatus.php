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

/**
 * One patch compliance status for the server and all apps together (design
 * D4 of advisories-per-app-detail). A pure function over the stored advisory
 * snapshot, so the page and `occ versioniq:advisories` cannot disagree.
 *
 */
final class ComplianceStatus {
	public const COMPLIANT = 'compliant';
	public const NOT_COMPLIANT = 'not_compliant';
	public const UNKNOWN = 'unknown';

	public const REASON_NEVER_CHECKED = 'never_checked';
	public const REASON_STALE = 'stale';
	public const REASON_UNREACHED = 'unreached';

	/** Highest first: the order a target's worst advisory is picked in, and the order counts are listed in. */
	private const SEVERITY_ORDER = [
		AdvisorySeverity::CRITICAL,
		AdvisorySeverity::HIGH,
		AdvisorySeverity::MEDIUM,
		AdvisorySeverity::LOW,
		AdvisorySeverity::UNKNOWN,
	];

	/**
	 * The compliance status of a stored snapshot.
	 *
	 * - `unknown` / `never_checked` when no check has completed;
	 * - `unknown` / `stale` when the last check is older than twice the interval;
	 * - `not_compliant` when any row's installed version is affected, with the
	 *   number of affected targets per highest severity and their ids;
	 * - `unknown` / `unreached` when nothing is affected but some rows carry an
	 *   error, with that number;
	 * - `compliant` otherwise.
	 *
	 * @param array<array-key, mixed> $snapshot The stored rows, keyed by app id (or the server key)
	 * @param ?int $checkedAt Unix time of the last completed check
	 * @param int $intervalHours The advisory check interval
	 * @param int $now Unix time now
	 * @return array{status: string, reason: ?string, affected: int, bySeverity: array<string, int>, affectedIds: list<string>, unreached: int, checkedAt: ?int}
	 *
	 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-the-instance-has-one-patch-compliance-status
	 */
	public static function from(array $snapshot, ?int $checkedAt, int $intervalHours, int $now): array {
		$result = [
			'status' => self::UNKNOWN,
			'reason' => null,
			'affected' => 0,
			'bySeverity' => [],
			'affectedIds' => [],
			'unreached' => 0,
			'checkedAt' => $checkedAt,
		];
		if ($checkedAt === null) {
			return array_replace($result, ['reason' => self::REASON_NEVER_CHECKED]);
		}
		if ($now - $checkedAt > 2 * $intervalHours * 3600) {
			return array_replace($result, ['reason' => self::REASON_STALE]);
		}

		$bySeverity = [];
		$affectedIds = [];
		$unreached = 0;
		/** @var mixed $row */
		foreach ($snapshot as $key => $row) {
			if (!is_array($row)) {
				continue;
			}
			if (($row['state'] ?? null) === AdvisoryService::STATE_VULNERABLE) {
				$affectedIds[] = (string)($row['appId'] ?? $key);
				$severity = self::highestSeverity($row['advisories'] ?? []);
				$bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
			} elseif (is_string($row['error'] ?? null) && $row['error'] !== '') {
				$unreached++;
			}
		}

		$result['unreached'] = $unreached;
		if ($affectedIds !== []) {
			return array_replace($result, [
				'status' => self::NOT_COMPLIANT,
				'affected' => count($affectedIds),
				'bySeverity' => self::ordered($bySeverity),
				'affectedIds' => $affectedIds,
			]);
		}
		if ($unreached > 0) {
			return array_replace($result, ['reason' => self::REASON_UNREACHED]);
		}

		return array_replace($result, ['status' => self::COMPLIANT]);
	}

	/**
	 * The worst severity among a row's affecting advisories.
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#3.1
	 */
	private static function highestSeverity(mixed $advisories): string {
		$found = [];
		if (is_array($advisories)) {
			/** @var mixed $advisory */
			foreach ($advisories as $advisory) {
				$found[] = AdvisorySeverity::normalize(is_array($advisory) ? ($advisory['severity'] ?? null) : null);
			}
		}
		foreach (self::SEVERITY_ORDER as $severity) {
			if (in_array($severity, $found, true)) {
				return $severity;
			}
		}

		return AdvisorySeverity::UNKNOWN;
	}

	/**
	 * The counts per severity, highest first.
	 *
	 * @param array<string, int> $counts
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#3.1
	 */
	private static function ordered(array $counts): array {
		$ordered = [];
		foreach (self::SEVERITY_ORDER as $severity) {
			if (isset($counts[$severity])) {
				$ordered[$severity] = $counts[$severity];
			}
		}

		return $ordered;
	}
}
