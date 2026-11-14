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
	 * The time end dates are judged by, or null when it is not one.
	 */
	private readonly ?DateTimeImmutable $now;

	/**
	 * A triage of the snapshot at one time.
	 *
	 * @param array<string, array<string, mixed>> $triage The triage map, keyed `{appId}|{advisoryId}`.
	 * @param string $now ISO 8601 time to judge end dates by.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	public function __construct(
		private readonly array $triage,
		string $now,
	) {
		$this->now = AdvisoryTriageStore::time($now);
	}

	/**
	 * Apply the triage map to one snapshot row.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param array<string, mixed> $row One row of the snapshot.
	 * @return array<string, mixed> The row with `dismissedAdvisories` and the state worked out again.
	 */
	public function apply(array $row): array {
		[$kept, $dismissed] = $this->split(row: $row);

		$row['advisories'] = $kept;
		$row['dismissedAdvisories'] = $dismissed;
		if ($dismissed === [] || $kept !== []) {
			return $row;
		}

		return self::settle(row: $row);
	}

	/**
	 * The ids of the advisories a triaged row holds as dismissed, as a set.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param array<string, mixed> $row A row returned by {@see apply()}.
	 * @return array<string, true>
	 */
	public static function dismissedIds(array $row): array {
		$list = $row['dismissedAdvisories'] ?? null;
		if (is_array($list) === false) {
			return [];
		}

		$ids = [];
		foreach (array_keys($list) as $index) {
			if (is_array($list[$index]) === true && isset($list[$index]['id']) === true && is_string($list[$index]['id']) === true) {
				$ids[$list[$index]['id']] = true;
			}
		}

		return $ids;
	}

	/**
	 * Split a row's advisories into the ones that count and the ones a dismissal
	 * still holds for, each carrying its owner when one is set.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
	 * @param array<string, mixed> $row One row of the snapshot.
	 * @return array{0: list<mixed>, 1: list<mixed>} The kept and the dismissed advisories.
	 */
	private function split(array $row): array {
		$kept = [];
		$dismissed = [];
		foreach ((array)($row['advisories'] ?? []) as $advisory) {
			$entry = $this->entryFor(row: $row, advisory: $advisory);
			if (is_array($entry['assignee'] ?? null) === true) {
				$advisory['assignee'] = $entry['assignee'];
			}

			if ($this->isActive(dismissal: $entry['dismissal'] ?? null, installed: $row['installedVersion'] ?? null) === true) {
				$dismissed[] = array_replace($advisory, ['dismissal' => $entry['dismissal']]);
				continue;
			}

			$kept[] = $advisory;
		}

		return [$kept, $dismissed];
	}

	/**
	 * The triage entry of one advisory of a row, or an empty one.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param array<string, mixed> $row The row.
	 * @param mixed $advisory The advisory.
	 * @return array<string, mixed>
	 */
	private function entryFor(array $row, mixed $advisory): array {
		$key = AdvisoryTriageStore::key((string)($row['appId'] ?? ''), (string)($advisory['id'] ?? ''));

		return $this->triage[$key] ?? [];
	}

	/**
	 * The state of a row whose every advisory is dismissed: vulnerable becomes
	 * available (and loses the recommendation), available becomes none.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param array<string, mixed> $row The row.
	 * @return array<string, mixed>
	 */
	private static function settle(array $row): array {
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
	 * Whether a dismissal still holds for the installed version.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param mixed $dismissal The stored dismissal, if any.
	 * @param mixed $installed The installed version.
	 */
	private function isActive(mixed $dismissal, mixed $installed): bool {
		if (is_array($dismissal) === false || ($dismissal['installedVersion'] ?? null) !== $installed) {
			return false;
		}

		return $this->stillOpen(until: $dismissal['until'] ?? null);
	}

	/**
	 * Whether a dismissal's end date is still ahead. No end date holds for good;
	 * an end date that cannot be read counts as passed, so the advisory counts again.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param mixed $until The end date.
	 */
	private function stillOpen(mixed $until): bool {
		if ($until === null || $until === '') {
			return true;
		}

		$end = AdvisoryTriageStore::time($until);

		return $end !== null && $this->now !== null && $end > $this->now;
	}
}
