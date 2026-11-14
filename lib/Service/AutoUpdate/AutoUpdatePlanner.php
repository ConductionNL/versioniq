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

use OCA\Versioniq\Service\Policy\Policy;

/**
 * The plan of an automatic update run, live against the bound sources, for
 * `occ versioniq:auto-update --dry-run` (autoupdate-proposed-updates D3).
 *
 * It applies the nightly job's rules in the job's order: pinned and
 * unmanaged apps are skipped before any source query; the highest version
 * within the level is the candidate; a version already attempted, declined,
 * still in its notice period or awaiting approval is held back with that
 * reason. It only reads: it installs nothing, writes no ledger entry and
 * sends no notification.
 *
 * @spec openspec/changes/autoupdate-proposed-updates/specs/cli-commands/spec.md#requirement-dry-run-the-automatic-update-policy-from-the-cli
 */
class AutoUpdatePlanner {
	public const ACTION_INSTALL = 'install';
	public const ACTION_SKIP = 'skip';

	public const REASON_PINNED = 'pinned';
	public const REASON_NOT_MANAGED = 'not_managed';
	public const REASON_NOT_INSTALLED = 'not_installed';
	public const REASON_SOURCE_ERROR = 'source_error';
	public const REASON_NO_CANDIDATE = 'no_candidate';
	public const REASON_BLOCKED = 'blocked';
	public const REASON_DECLINED = 'declined';
	public const REASON_IN_NOTICE = 'in_notice';
	public const REASON_AWAITING_APPROVAL = 'awaiting_approval';

	public function __construct(
		private AutoUpdateStores $stores,
		private AutoUpdateInstall $install,
	) {
	}

	/**
	 * One row per app with a policy other than `none`, in stored order.
	 *
	 * @param \DateTimeInterface $at The opening the plan is for.
	 * @return list<array{appId: string, level: string, fromVersion: ?string, toVersion: ?string, action: string, reason: ?string}>
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/cli-commands/spec.md#requirement-dry-run-the-automatic-update-policy-from-the-cli
	 */
	public function plan(\DateTimeInterface $at): array {
		$rows = [];
		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE) {
				continue;
			}
			$rows[] = $this->rowFor($appId, $policy, $at);
		}

		return $rows;
	}

	/**
	 * @return array{appId: string, level: string, fromVersion: ?string, toVersion: ?string, action: string, reason: ?string}
	 */
	private function rowFor(string $appId, Policy $policy, \DateTimeInterface $at): array {
		$row = ['appId' => $appId, 'level' => $policy->level, 'fromVersion' => null, 'toVersion' => null, 'action' => self::ACTION_SKIP, 'reason' => null];
		if ($this->stores->pinStore->get($appId) !== null) {
			return ['reason' => self::REASON_PINNED] + $row;
		}
		if (!$this->install->installerService->isManageableApp($appId)) {
			return ['reason' => self::REASON_NOT_MANAGED] + $row;
		}

		$installed = $this->installedVersionOf($appId);
		if ($installed === null) {
			return ['reason' => self::REASON_NOT_INSTALLED] + $row;
		}
		$row['fromVersion'] = $installed;

		try {
			$available = $this->availableVersions($appId);
		} catch (\Throwable) {
			$available = null;
		}
		if ($available === null) {
			return ['reason' => self::REASON_SOURCE_ERROR] + $row;
		}

		$candidate = $this->install->candidateSelector->select($installed, $available, $policy->level);
		if ($candidate === null) {
			return ['reason' => self::REASON_NO_CANDIDATE] + $row;
		}
		$row['toVersion'] = $candidate;

		$held = $this->heldBackBecause($appId, $candidate, $policy, $at);

		return $held === null ? ['action' => self::ACTION_INSTALL] + $row : ['reason' => $held] + $row;
	}

	/**
	 * Why the job would hold this version back, in the job's order, or null.
	 */
	private function heldBackBecause(string $appId, string $candidate, Policy $policy, \DateTimeInterface $at): ?string {
		if ($this->stores->attemptLedger->hasAttempted($appId, $candidate)) {
			return self::REASON_BLOCKED;
		}

		$proposals = $this->stores->proposalStore;
		if ($proposals === null) {
			return null;
		}
		if ($proposals->declineOf($appId, $candidate) !== null) {
			return self::REASON_DECLINED;
		}
		if ($this->stores->settingsStore->getNoticeDays() > 0 && !self::noticeHasPassed($proposals->proposalFor($appId), $candidate, $at)) {
			return self::REASON_IN_NOTICE;
		}

		return $policy->requireApproval && !$proposals->isApproved($appId, $candidate) ? self::REASON_AWAITING_APPROVAL : null;
	}

	private static function noticeHasPassed(?ProposedUpdate $proposal, string $candidate, \DateTimeInterface $at): bool {
		if ($proposal === null || $proposal->toVersion !== $candidate || $proposal->plannedFor === null) {
			return false;
		}

		return new \DateTimeImmutable($proposal->plannedFor) <= $at;
	}

	private function installedVersionOf(string $appId): ?string {
		try {
			$installed = $this->install->appManager->getAppVersion($appId, false);
		} catch (\Throwable) {
			return null;
		}

		return $installed !== '' ? $installed : null;
	}

	/**
	 * @return list<string>|null Null when the source reported an error.
	 */
	private function availableVersions(string $appId): ?array {
		$result = $this->install->installerService->getAppVersions($appId);
		if (($result['hasError'] ?? false) === true) {
			return null;
		}

		return array_map(
			static fn (array $entry): string => $entry['version'],
			$result['availableVersions'] ?? [],
		);
	}
}
