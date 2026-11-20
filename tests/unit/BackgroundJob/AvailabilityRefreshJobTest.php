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
use OCA\Versioniq\Service\ReleaseAlert\ReleaseNotifier;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseSweepRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Versioniq\Service\ReleaseAlert\FollowList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/pending-updates/spec.md
 *
 * @uses \OCA\Versioniq\Service\ReleaseAlert\ReleaseSweepRecorder
 * @uses \OCA\Versioniq\Service\ReleaseAlert\ReleaseEventDetector
 * @uses \OCA\Versioniq\Service\ReleaseAlert\FollowList
 */
final class AvailabilityRefreshJobTest extends TestCase {
	private function runJob(AvailabilityService $service, AvailabilityResultStore $store, ?LoggerInterface $logger = null, ?FollowList $follows = null): AvailabilityRefreshJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);

		$recorder = new ReleaseSweepRecorder($store, $this->createMock(ReleaseNotifier::class), $follows);
		$job = new AvailabilityRefreshJob($time, $service, $recorder, $logger ?? $this->createMock(LoggerInterface::class));
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
		$store->expects(self::once())->method('saveFailure')->with(1_790_000_000);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$this->runJob($service, $store, $logger);
	}

	/**
	 * Recording the failure must not throw out of the job either.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function testAFailureThatCannotBeRecordedIsLogged(): void {
		$service = $this->createMock(AvailabilityService::class);
		$service->method('sweep')->willThrowException(new \RuntimeException('boom'));
		$store = $this->createMock(AvailabilityResultStore::class);
		$store->method('saveFailure')->willThrowException(new \RuntimeException('db'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))->method('error');

		$this->runJob($service, $store, $logger);
	}

	/**
	 * releases-new-release-alerts D3: the followed apps reach the sweep.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-an-admin-follows-the-releases-of-an-app-that-is-not-installed
	 */
	public function testTheFollowedAppsReachTheSweep(): void {
		$followed = [['appId' => 'forms', 'sourceId' => 'appstore', 'addedBy' => 'alice', 'addedAt' => '2026-11-14T10:00:00+00:00']];
		$follows = $this->createMock(FollowList::class);
		$follows->method('all')->willReturn($followed);

		$service = $this->createMock(AvailabilityService::class);
		$service->expects(self::once())->method('sweep')->with(AvailabilityRefreshJob::SWEEP_BUDGET_SECONDS, $followed)->willReturn([]);

		$this->runJob($service, $this->createMock(AvailabilityResultStore::class), null, $follows);
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

		$recorder = new ReleaseSweepRecorder($store, $this->createMock(ReleaseNotifier::class));
		$job = new AvailabilityRefreshJob($time, $service, $recorder, $logger, $proposals);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
