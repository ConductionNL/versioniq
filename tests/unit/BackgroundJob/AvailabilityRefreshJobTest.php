<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\BackgroundJob\AvailabilityRefreshJob;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/pending-updates/spec.md
 */
final class AvailabilityRefreshJobTest extends TestCase {
	private function runJob(AvailabilityService $service, AvailabilityResultStore $store, ?LoggerInterface $logger = null): AvailabilityRefreshJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);

		$job = new AvailabilityRefreshJob($time, $service, $store, $logger ?? $this->createMock(LoggerInterface::class));
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);

		return $job;
	}

	public function testSweepsWithTheJobBudgetAndStoresTheSnapshotWithTheSweepTime(): void {
		$snapshot = ['deck' => ['installedVersion' => '1.0.0', 'error' => null], 'hermiq' => ['installedVersion' => '1.0.0', 'error' => 'HTTP 403']];

		$service = $this->createMock(AvailabilityService::class);
		$service->expects(self::once())->method('sweep')->with(AvailabilityRefreshJob::SWEEP_BUDGET_SECONDS)->willReturn($snapshot);

		$store = $this->createMock(AvailabilityResultStore::class);
		$store->expects(self::once())->method('save')->with($snapshot, 1_790_000_000);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('could not be checked'), ['unreached' => 1, 'total' => 2]);

		$this->runJob($service, $store, $logger);
	}

	public function testRunsEverySixHours(): void {
		$job = $this->runJob($this->createMock(AvailabilityService::class), $this->createMock(AvailabilityResultStore::class));

		self::assertSame(6 * 3600, $job->getInterval());
	}

	public function testAFailedSweepKeepsThePreviousSnapshotAndIsLogged(): void {
		$service = $this->createMock(AvailabilityService::class);
		$service->method('sweep')->willThrowException(new \RuntimeException('boom'));

		$store = $this->createMock(AvailabilityResultStore::class);
		$store->expects(self::never())->method('save');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$this->runJob($service, $store, $logger);
	}
}
