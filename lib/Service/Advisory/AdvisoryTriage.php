<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

use DateTimeImmutable;
use Exception;

/**
 * Applies the stored triage to one row of the advisory snapshot (advisories-triage, design D2).
 *
 * The one function every reader uses (controller, notifier, digest, job), so
 * no reader can disagree about what is dismissed. It leaves the snapshot
 * itself alone: a revert leaves a correct snapshot behind.
 *
 * A dismissal is ACTIVE while its `until` is empty or still ahead and the
 * app's installed version equals the version it was dismissed on. An actively
 * dismissed advisory moves from `advisories` to `dismissedAdvisories`, with
 * its dismissal, and the row's state is worked out again from what is left:
 *
 *   - a `pinned-to-vulnerable` row keeps its state and its recommended version
 *     while an affecting advisory remains, and drops to `advisory-available`
 *     (and loses the recommendation) when none does;
 *   - an `advisory-available` row drops to `none` when nothing remains.
 *
 * A lapsed dismissal (end date passed, or another version installed) is
 * ignored and the advisory counts again. Each kept advisory carries its owner
 * as `assignee` when one is set.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 *
 * @psalm-api
 */
final class AdvisoryTriage {
	/**
	 * Apply the triage map to one snapshot row.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param array<string, mixed> $row One row of the snapshot.
	 * @param array<string, array<string, mixed>> $triage The triage map, keyed `{appId}|{advisoryId}`.
	 * @param string $now ISO 8601 time to judge end dates by.
	 * @return array<string, mixed> The row with `dismissedAdvisories` and the state worked out again.
	 */
	public static function apply(array $row, array $triage, string $now): array {
		$appId = (string)($row['appId'] ?? '');
		$installed = $row['installedVersion'] ?? null;
		$at = self::time($now);
		$kept = [];
		$dismissed = [];
		foreach ((array)($row['advisories'] ?? []) as $advisory) {
			$entry = $triage[AdvisoryTriageStore::key($appId, (string)($advisory['id'] ?? ''))] ?? [];
			if (isset($entry['assignee']) === true && is_array($entry['assignee']) === true) {
				$advisory['assignee'] = $entry['assignee'];
			}

			$dismissal = $entry['dismissal'] ?? null;
			if (is_array($dismissal) === true && self::isActive(dismissal: $dismissal, installed: $installed, now: $at) === true) {
				$dismissed[] = array_replace($advisory, ['dismissal' => $dismissal]);
				continue;
			}

			$kept[] = $advisory;
		}

		$row['advisories'] = $kept;
		$row['dismissedAdvisories'] = $dismissed;
		if ($dismissed === [] || $kept !== []) {
			return $row;
		}

		$state = $row['state'] ?? null;
		if ($state === AdvisoryService::STATE_VULNERABLE) {
			$row['state'] = AdvisoryService::STATE_AVAILABLE;
			$row['recommendedVersion'] = null;
		} elseif ($state === AdvisoryService::STATE_AVAILABLE) {
			$row['state'] = AdvisoryService::STATE_NONE;
		}

		return $row;
	}

	/**
	 * Whether a dismissal still holds for the installed version at this time.
	 *
	 * @param array<string, mixed> $dismissal The stored dismissal.
	 */
	private static function isActive(array $dismissal, mixed $installed, ?DateTimeImmutable $now): bool {
		if (($dismissal['installedVersion'] ?? null) !== $installed) {
			return false;
		}

		$until = $dismissal['until'] ?? null;
		if ($until === null || $until === '') {
			return true;
		}

		$end = self::time($until);
		if ($end === null || $now === null) {
			// An end date that cannot be read counts as passed: the advisory counts again.
			return false;
		}

		return $end > $now;
	}

	/**
	 * A time, or null when it is not one.
	 */
	private static function time(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}
}
