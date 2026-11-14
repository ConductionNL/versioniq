<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\BackgroundJob\JobRuntime;
use OCA\Versioniq\BackgroundJob\SnapshotJob;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\Snapshot\InstanceSnapshot;
use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The daily snapshot job (install-bulk-rollback design D2).
 *
 * @covers \OCA\Versioniq\BackgroundJob\SnapshotJob
 * @uses \OCA\Versioniq\BackgroundJob\JobRuntime
 * @uses \OCA\Versioniq\Service\Audit\AuditOrigin
 * @uses \OCA\Versioniq\Service\Snapshot\InstanceSnapshot
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
final class SnapshotJobTest extends TestCase {
	private function runJob(SnapshotJob $job): void {
		(new ReflectionMethod(SnapshotJob::class, 'run'))->invoke($job, null);
	}

	public function testItTakesADailySnapshotAsTheSystemUnderTheJobOrigin(): void {
		$taker = $this->createMock(SnapshotTaker::class);
		$origin = null;
		$taker->expects($this->once())->method('take')->with('daily', 'system')
			->willReturnCallback(function () use (&$origin): InstanceSnapshot {
				$origin = AuditOrigin::current();

				return new InstanceSnapshot(['id' => 's1', 'takenAt' => '2026-09-26T00:00:00+00:00', 'takenBy' => 'system', 'reason' => 'daily'], []);
			});
		$job = new SnapshotJob(new JobRuntime($this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class)), $taker);

		$this->runJob($job);

		$this->assertSame(AuditOrigin::JOB, $origin);
	}

	public function testItRunsOncePerDay(): void {
		$job = new SnapshotJob(new JobRuntime($this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class)), $this->createMock(SnapshotTaker::class));

		$this->assertSame(86400, $job->getInterval());
	}

	public function testAFailureIsLoggedAndSwallowed(): void {
		$taker = $this->createMock(SnapshotTaker::class);
		$taker->method('take')->willThrowException(new \RuntimeException('config down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('SnapshotJob: the daily snapshot failed', ['message' => 'config down']);
		$job = new SnapshotJob(new JobRuntime($this->createMock(ITimeFactory::class), $logger), $taker);

		$this->runJob($job);
	}
}
