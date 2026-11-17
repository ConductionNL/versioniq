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
 * A unit is one app, or one update group (D2): a group costs the number of
 * its members that would install, and a group that does not fit what is left
 * waits whole.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class AutoUpdateLimiter {
	private const UNIT_PREFIX = 'app:';
	private const GROUP_PREFIX = 'group:';

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

		[$installable, $others, $deferred] = self::partition($members, $deferred);

		$ordered = [];
		foreach ($this->runOrder($installable, $deferred) as $key => $unit) {
			$cost = self::cost($unit);
			if (self::fits($budget, $cost)) {
				$budget = self::spend($budget, $cost);
				unset($deferred[$key]);
				array_push($ordered, ...$unit);
				continue;
			}

			// The unit does not fit what is left: it waits whole (D4).
			$deferred[$key] ??= $now->format(\DateTimeInterface::ATOM);
			array_push($ordered, ...self::heldBack($unit));
		}

		$this->settings->setDeferred($deferred);

		return array_merge($ordered, $others);
	}

	/**
	 * Whether a unit of this cost fits what is left; everything fits without a cap.
	 */
	private static function fits(?int $budget, int $cost): bool {
		return $budget === null || $budget >= $cost;
	}

	/**
	 * What is left after a unit of this cost; no cap stays no cap.
	 */
	private static function spend(?int $budget, int $cost): ?int {
		return ($budget === null) ? null : $budget - $cost;
	}

	/**
	 * The unit's members, those that would install marked `limit`.
	 *
	 * @param list<AutoUpdateMember> $unit
	 *
	 * @return list<AutoUpdateMember>
	 */
	private static function heldBack(array $unit): array {
		return array_map(
			static fn (AutoUpdateMember $member): AutoUpdateMember => $member->willInstall() ? $member->withReason(AutoUpdateMember::REASON_LIMIT) : $member,
			$unit
		);
	}

	/**
	 * Splits the members into the units that would install something and the
	 * members that would not, and lets go of the hold on a unit with nothing
	 * left to install.
	 *
	 * @param list<AutoUpdateMember> $members  The planned members.
	 * @param array<string, string>  $deferred The held units, by key.
	 *
	 * @return array{0: array<string, list<AutoUpdateMember>>, 1: list<AutoUpdateMember>, 2: array<string, string>}
	 */
	private static function partition(array $members, array $deferred): array {
		$installable = [];
		$others = [];
		foreach (self::units($members) as $key => $unit) {
			if (self::cost($unit) > 0) {
				$installable[$key] = $unit;
				continue;
			}

			array_push($others, ...$unit);
			if (self::hasNoCandidate($unit)) {
				unset($deferred[$key]);
			}
		}

		return [$installable, $others, $deferred];
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
	 * Held units first, oldest first, then the rest by app id or group name.
	 *
	 * @param array<string, list<AutoUpdateMember>> $units
	 * @param array<string, string> $deferred
	 *
	 * @return array<string, list<AutoUpdateMember>>
	 */
	private function runOrder(array $units, array $deferred): array {
		uksort($units, static fn (string $a, string $b): int => self::compareUnits($a, $b, $deferred));

		return $units;
	}

	/**
	 * Run order of two unit keys: the longer held first, held before not held, then by name.
	 *
	 * @param array<string, string> $deferred
	 */
	private static function compareUnits(string $a, string $b, array $deferred): int {
		$heldA = $deferred[$a] ?? null;
		$heldB = $deferred[$b] ?? null;
		if (($heldA === null) !== ($heldB === null)) {
			return ($heldA === null) ? 1 : -1;
		}

		if ($heldA !== $heldB) {
			return strcmp((string)$heldA, (string)$heldB);
		}

		return strcmp(self::sortName($a), self::sortName($b));
	}

	/**
	 * The members by unit: one unit per app, one per update group, in the
	 * order they were planned. A group's members stay together in its order.
	 *
	 * @param list<AutoUpdateMember> $members
	 *
	 * @return array<string, list<AutoUpdateMember>>
	 */
	private static function units(array $members): array {
		$units = [];
		foreach ($members as $member) {
			$units[self::unitKey($member)][] = $member;
		}

		return $units;
	}

	/**
	 * What a unit costs against the caps: the members that would install.
	 *
	 * @param list<AutoUpdateMember> $unit
	 */
	private static function cost(array $unit): int {
		return count(array_filter($unit, static fn (AutoUpdateMember $member): bool => $member->willInstall()));
	}

	/**
	 * Whether no member of the unit has a candidate, so it leaves the waiting list.
	 *
	 * @param list<AutoUpdateMember> $unit
	 */
	private static function hasNoCandidate(array $unit): bool {
		foreach ($unit as $member) {
			if ($member->reason !== AutoUpdateMember::REASON_NO_CANDIDATE) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The app id or group name a unit sorts by.
	 */
	private static function sortName(string $key): string {
		return substr($key, (int)strpos($key, ':') + 1);
	}

	/**
	 * The waiting-list key of a member's unit.
	 */
	private static function unitKey(AutoUpdateMember $member): string {
		return $member->group === null ? self::UNIT_PREFIX . $member->appId : self::GROUP_PREFIX . $member->group;
	}
}
