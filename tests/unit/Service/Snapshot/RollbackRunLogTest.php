<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Snapshot;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Snapshot\RollbackRunLog;
use PHPUnit\Framework\TestCase;

/**
 * The `bulk_rollback` rows at the start and the end of a run
 * (install-bulk-rollback design D4).
 *
 * @covers \OCA\Versioniq\Service\Snapshot\RollbackRunLog
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
final class RollbackRunLogTest extends TestCase {
	/** @var list<array{0: AuditSubject, 1: string, 2: ?string}> */
	private array $rows = [];

	private function log(): RollbackRunLog {
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->rows[] = [$subject, $status, $message];
		});

		return new RollbackRunLog($audit);
	}

	public function testTheStartRowNamesTheSnapshotAndTheSteps(): void {
		$this->log()->started('alice', 's1', 2);

		[$subject, $status, $message] = $this->rows[0];
		$this->assertSame(['alice', 'versioniq', 'bulk_rollback'], [$subject->actorUid, $subject->appId, $subject->operation]);
		$this->assertSame(AuditLogger::STATUS_SUCCESS, $status);
		$this->assertSame('Rollback to snapshot s1 started: 2 steps', $message);
	}

	public function testTheEndRowCountsTheStepsAndFailsWhenOneFailed(): void {
		$log = $this->log();
		$log->finished('alice', 's1', 2, 0, 0);
		$log->finished('', 's1', 1, 1, 1);

		$this->assertSame(AuditLogger::STATUS_SUCCESS, $this->rows[0][1]);
		$this->assertSame('Rollback to snapshot s1 finished: 2 succeeded, 0 failed, 0 skipped', $this->rows[0][2]);
		$this->assertSame(AuditLogger::STATUS_FAILURE, $this->rows[1][1]);
		$this->assertSame('system', $this->rows[1][0]->actorUid);
		$this->assertSame('Rollback to snapshot s1 finished: 1 succeeded, 1 failed, 1 skipped', $this->rows[1][2]);
	}

	public function testANegativeCountIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		try {
			$this->log()->finished('alice', 's1', 1, -1, 0);
		} finally {
			$this->assertSame([], $this->rows);
		}
	}
}
