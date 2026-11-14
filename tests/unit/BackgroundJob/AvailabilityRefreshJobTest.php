<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\BackgroundJob\AvailabilityRefreshJob;
use OCA\Versioniq\Service\AutoUpdate\ProposalService;
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

	public function testTheProposalStepRunsOnTheSweepsListsAfterTheSnapshotIsSaved(): void {
		$listings = ['deck' => ['installedVersion' => '1.9.0', 'versions' => [['version' => '1.9.3']]]];
		$order = [];
		$service = $this->createMock(AvailabilityService::class);
		$service->method('sweep')->willReturn([]);
		$service->method('lastListings')->willReturn($listings);
		$store = $this->createMock(AvailabilityResultStore::class);
		$store->method('save')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'save';
		});
		$proposals = $this->createMock(ProposalService::class);
		$proposals->expects(self::once())->method('refresh')
			->with($listings, self::callback(static fn (\DateTimeImmutable $now): bool => $now->format(DATE_ATOM) === '2026-10-01T10:00:00+00:00'))
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'refresh';
			});

		$this->runWithProposals($service, $store, $proposals, $this->createMock(LoggerInterface::class));

		self::assertSame(['save', 'refresh'], $order);
	}

	public function testAFailingProposalStepIsLoggedAndKeepsTheSavedSweep(): void {
		$service = $this->createMock(AvailabilityService::class);
		$service->method('sweep')->willReturn([]);
		$service->method('lastListings')->willReturn([]);
		$store = $this->createMock(AvailabilityResultStore::class);
		$store->expects(self::once())->method('save');
		$proposals = $this->createMock(ProposalService::class);
		$proposals->method('refresh')->willThrowException(new \RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')
			->with('AvailabilityRefreshJob: the proposed updates could not be worked out; the previous ones are kept', ['message' => 'boom']);

		$this->runWithProposals($service, $store, $proposals, $logger);
	}

	private function runWithProposals(AvailabilityService $service, AvailabilityResultStore $store, ProposalService $proposals, LoggerInterface $logger): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-01T10:00:00+00:00'));

		$job = new AvailabilityRefreshJob($time, $service, $store, $logger, $proposals);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
