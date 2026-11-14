<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Snapshot;

use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;

/**
 * Gathers what {@see RollbackPlanner} needs from the instance: the apps
 * installed now, the pins, and per moving app the time of its newest audit row
 * after the snapshot (install-bulk-rollback design D3). Local only; no source
 * is called.
 *
 * @psalm-import-type RollbackStep from RollbackPlanner
 * @psalm-api
 */
class SnapshotPlans {
	public function __construct(
		private InstallerService $installerService,
		private PinStore $pinStore,
		private AuditEntryMapper $auditMapper,
		private RollbackPlanner $planner,
	) {
	}

	/**
	 * The plan to move the instance back to a snapshot.
	 *
	 * @return list<RollbackStep>
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function planFor(InstanceSnapshot $snapshot): array {
		$installed = $this->installerService->getInstalledApps();
		$pins = [];
		foreach ($this->pinStore->all() as $appId => $pin) {
			$pins[(string)$appId] = $pin->version;
		}
		$draft = $this->planner->plan($snapshot, $installed, $pins);

		$lastChangedAt = [];
		foreach ($draft as $step) {
			if ($step['kind'] !== 'downgrade' && $step['kind'] !== 'upgrade') {
				continue;
			}
			$changedAt = $this->newestChangeAfter($step['appId'], $snapshot->takenAt);
			if ($changedAt !== null) {
				$lastChangedAt[$step['appId']] = $changedAt;
			}
		}

		return $lastChangedAt === [] ? $draft : $this->planner->plan($snapshot, $installed, $pins, $lastChangedAt);
	}

	/**
	 * The time (ISO 8601, UTC) of the app's newest audit row when it is later
	 * than the snapshot, else null.
	 */
	private function newestChangeAfter(string $appId, string $takenAt): ?string {
		$newest = $this->auditMapper->findPage($appId, 1, 0)[0] ?? null;
		if ($newest === null) {
			return null;
		}
		$at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $newest->getCreatedAt(), new \DateTimeZone('UTC'));
		$snapshotAt = \DateTimeImmutable::createFromFormat(DATE_ATOM, $takenAt);
		if ($at === false || $snapshotAt === false || $at <= $snapshotAt) {
			return null;
		}

		return $at->format(DATE_ATOM);
	}
}
