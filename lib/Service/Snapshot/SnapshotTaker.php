<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Snapshot;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\InstallerService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Takes a snapshot of every managed app now, stores it and records the
 * `snapshot` audit row (install-bulk-rollback design D1 and D2). The single
 * write path for the daily job, the automatic update job, the page and the
 * commands.
 *
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 * @psalm-api
 */
class SnapshotTaker {
	public function __construct(
		private InstallerService $installerService,
		private SnapshotStore $store,
		private AuditLogger $auditLogger,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Take, store and audit a snapshot.
	 *
	 * @param string $reason One of {@see InstanceSnapshot::REASONS}.
	 * @param string $takenBy The uid of the admin, or `system`.
	 * @param string|null $name An optional name; a named snapshot is kept until deleted.
	 * @throws \InvalidArgumentException on an unknown reason
	 * @throws \OverflowException when a named snapshot would pass the named cap
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function take(string $reason, string $takenBy, ?string $name = null): InstanceSnapshot {
		$now = $this->time->getDateTime('now', new \DateTimeZone('UTC'));
		$name = $name !== null && trim($name) !== '' ? trim($name) : null;
		$snapshot = InstanceSnapshot::fromInstalledApps(
			[
				'id' => $this->newId($now),
				'takenAt' => $now->format(DATE_ATOM),
				'takenBy' => $takenBy !== '' ? $takenBy : 'system',
				'reason' => $reason,
				'name' => $name,
			],
			$this->installerService->getInstalledApps(),
		);
		$this->store->save($snapshot);

		$this->auditLogger->record(
			new AuditSubject($snapshot->takenBy, Application::APP_ID, AuditLogger::OPERATION_SNAPSHOT),
			new AuditedVersions(null, null, null),
			AuditLogger::STATUS_SUCCESS,
			sprintf('Snapshot %s (%s) of %d apps', $snapshot->id, $snapshot->reason, count($snapshot->apps)),
		);

		return $snapshot;
	}

	/**
	 * A sortable, time-based id: the UTC time to the microsecond.
	 */
	private function newId(\DateTimeInterface $now): string {
		return $now->format('Ymd\THis') . '-' . sprintf('%06d', (int)$now->format('u'));
	}
}
