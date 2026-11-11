<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\BackgroundJob\PruneAuditJob;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class PruneAuditJobTest extends TestCase {
	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			static fn (string $time = 'now', ?\DateTimeZone $tz = null): \DateTime => new \DateTime('2026-07-23T00:00:00+00:00', $tz)
		);

		return $time;
	}

	private function runJob(PruneAuditJob $job): void {
		$method = new ReflectionMethod(PruneAuditJob::class, 'run');
		$method->invoke($job, null);
	}

	public function testDefaultRetentionDeletesEntriesOlderThan365Days(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')
			->with(Application::APP_ID, PruneAuditJob::CONFIG_KEY_RETENTION_DAYS, PruneAuditJob::DEFAULT_RETENTION_DAYS)
			->willReturn(PruneAuditJob::DEFAULT_RETENTION_DAYS);

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->once())
			->method('deleteOlderThan')
			->with($this->callback(function (\DateTimeImmutable $cutoff): bool {
				// 2026-07-23 minus 365 days.
				return $cutoff->format('Y-m-d') === '2025-07-23';
			}), 1000)
			->willReturn(1);

		$job = new PruneAuditJob($this->timeFactory(), $mapper, $appConfig, $this->createMock(LoggerInterface::class));
		$this->runJob($job);
	}

	public function testRetentionFloorIsEnforcedAndClampIsLogged(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(7);

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->once())
			->method('deleteOlderThan')
			->with($this->callback(function (\DateTimeImmutable $cutoff): bool {
				// Clamped to the 30-day floor, not the configured 7.
				return $cutoff->format('Y-m-d') === '2026-06-23';
			}), 1000)
			->willReturn(0);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$job = new PruneAuditJob($this->timeFactory(), $mapper, $appConfig, $logger);
		$this->runJob($job);
	}

	public function testBatchesUntilAPartialBatchIsReturned(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(PruneAuditJob::DEFAULT_RETENTION_DAYS);

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->exactly(3))
			->method('deleteOlderThan')
			->willReturnOnConsecutiveCalls(1000, 1000, 400);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->anything(),
			$this->callback(fn (array $context): bool => $context['deleted'] === 2400)
		);

		$job = new PruneAuditJob($this->timeFactory(), $mapper, $appConfig, $logger);
		$this->runJob($job);
	}

	public function testMapperFailureIsLoggedAndDoesNotThrow(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(PruneAuditJob::DEFAULT_RETENTION_DAYS);

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('deleteOlderThan')->willThrowException(new \RuntimeException('db down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$job = new PruneAuditJob($this->timeFactory(), $mapper, $appConfig, $logger);
		$this->runJob($job);
		$this->addToAssertionCount(1);
	}
}
