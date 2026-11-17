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

/**
 * Records the start and the end of a rollback run as `bulk_rollback` audit
 * rows (install-bulk-rollback design D4 and D5). The installs of the run are
 * audited by the installer itself.
 *
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 * @psalm-api
 */
class RollbackRunLog {
	public function __construct(
		private AuditLogger $auditLogger,
	) {
	}

	/**
	 * The row at the start of a run.
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function started(string $actorUid, string $snapshotId, int $steps): void {
		$this->write($actorUid, AuditLogger::STATUS_SUCCESS, sprintf('Rollback to snapshot %s started: %d steps', $snapshotId, $steps));
	}

	/**
	 * The row at the end of a run; a failure status when any step failed.
	 *
	 * @param array{succeeded: int, failed: int, skipped: int} $counts The steps per outcome, as {@see RollbackRunner::counts()} gives them.
	 * @throws \InvalidArgumentException on a negative count
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	public function finished(string $actorUid, string $snapshotId, array $counts): void {
		if (min($counts['succeeded'], $counts['failed'], $counts['skipped']) < 0) {
			throw new \InvalidArgumentException('Step counts cannot be negative');
		}
		$this->write(
			$actorUid,
			$counts['failed'] > 0 ? AuditLogger::STATUS_FAILURE : AuditLogger::STATUS_SUCCESS,
			sprintf('Rollback to snapshot %s finished: %d succeeded, %d failed, %d skipped', $snapshotId, $counts['succeeded'], $counts['failed'], $counts['skipped']),
		);
	}

	private function write(string $actorUid, string $status, string $message): void {
		$this->auditLogger->record(
			new AuditSubject($actorUid !== '' ? $actorUid : 'system', Application::APP_ID, AuditLogger::OPERATION_BULK_ROLLBACK),
			new AuditedVersions(null, null, null),
			$status,
			$message,
		);
	}
}
