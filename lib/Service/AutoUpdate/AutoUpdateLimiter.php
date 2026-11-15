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

/**
 * Applies the per-run and weekly caps and the waiting list to a plan
 * (autoupdate-run-batching D4 and D5).
 *
 * A run may install at most min(max_per_run, max_per_week - attempts in the
 * last seven days). Units the cap held back last time go first, oldest
 * first, then the rest by app id. A unit that does not fit waits with reason
 * `limit` and joins the waiting list; it leaves the list when it installs or
 * no longer has a candidate. Without a cap and with an empty waiting list the
 * plan is returned as it came, so nothing changes until an admin sets a cap.
 * Each unit is one app until update groups are planned (D2); a unit's cost is
 * one.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class AutoUpdateLimiter {
	private const UNIT_PREFIX = 'app:';

	public function __construct(
		private AutoUpdateSettingsStore $settings,
		private AttemptLedger $ledger,
	) {
	}

	/**
	 * The members in run order, with those the caps hold back marked `limit`.
	 *
	 * @param list<AutoUpdateMember> $members The planned members.
	 *
	 * @return list<AutoUpdateMember>
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function apply(array $members, \DateTimeImmutable $now): array {
		$budget = $this->budget($now);
		$deferred = $this->settings->getDeferred();
		if ($budget === null && $deferred === []) {
			return $members;
		}

		$installable = array_values(array_filter($members, static fn (AutoUpdateMember $member): bool => $member->willInstall()));
		$others = array_values(array_filter($members, static fn (AutoUpdateMember $member): bool => !$member->willInstall()));

		$ordered = [];
		foreach ($this->runOrder($installable, $deferred) as $member) {
			$key = self::unitKey($member);
			if ($budget === null || $budget >= 1) {
				$budget = ($budget === null) ? null : $budget - 1;
				unset($deferred[$key]);
				$ordered[] = $member;
				continue;
			}

			$deferred[$key] ??= $now->format(\DateTimeInterface::ATOM);
			$ordered[] = new AutoUpdateMember($member->appId, $member->installedVersion, $member->candidate, AutoUpdateMember::REASON_LIMIT);
		}

		foreach ($others as $member) {
			if ($member->reason === AutoUpdateMember::REASON_NO_CANDIDATE) {
				unset($deferred[self::unitKey($member)]);
			}
		}

		$this->settings->setDeferred($deferred);

		return array_merge($ordered, $others);
	}

	/**
	 * What this run may still install, or null without a cap.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function budget(\DateTimeImmutable $now): ?int {
		$perRun = $this->settings->getMaxPerRun();
		$perWeek = $this->settings->getMaxPerWeek();
		if ($perWeek !== null) {
			$used = $this->ledger->countSince($now->modify('-7 days')->format(\DateTimeInterface::ATOM));
			$perWeek = max(0, $perWeek - $used);
		}

		if ($perRun === null) {
			return $perWeek;
		}

		return ($perWeek === null) ? $perRun : min($perRun, $perWeek);
	}

	/**
	 * Held units first, oldest first, then the rest by app id.
	 *
	 * @param list<AutoUpdateMember> $members
	 * @param array<string, string> $deferred
	 *
	 * @return list<AutoUpdateMember>
	 */
	private function runOrder(array $members, array $deferred): array {
		usort($members, static function (AutoUpdateMember $a, AutoUpdateMember $b) use ($deferred): int {
			$heldA = $deferred[self::unitKey($a)] ?? null;
			$heldB = $deferred[self::unitKey($b)] ?? null;
			if ($heldA !== null && $heldB !== null && $heldA !== $heldB) {
				return strcmp($heldA, $heldB);
			}

			if (($heldA === null) !== ($heldB === null)) {
				return ($heldA === null) ? 1 : -1;
			}

			return strcmp($a->appId, $b->appId);
		});

		return $members;
	}

	/**
	 * The waiting-list key of a member's unit.
	 */
	private static function unitKey(AutoUpdateMember $member): string {
		return self::UNIT_PREFIX . $member->appId;
	}
}
