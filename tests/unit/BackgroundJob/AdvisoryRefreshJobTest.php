<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\BackgroundJob\AdvisoryRefreshJob;
use OCA\Versioniq\Service\Advisory\AdvisoryDigestNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdvisoryRefreshJobTest extends TestCase {
	use BuildsByParameterName;

	private function correlation(string $appId, ?string $error = null): array {
		return [
			'appId' => $appId,
			'installedVersion' => '1.0.0',
			'state' => AdvisoryService::STATE_NONE,
			'advisories' => [],
			'recommendedVersion' => null,
			'error' => $error,
		];
	}

	private function runJob(
		AdvisoryService $service,
		AdvisoryResultStore $store,
		?AdvisoryNotifier $notifier = null,
		?LoggerInterface $logger = null,
		int $now = 1_700_000_000,
		?AdvisoryDigestNotifier $digestNotifier = null,
	): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$settings = $this->createMock(AdvisorySettingsStore::class);
		$settings->method('getIntervalSeconds')->willReturn(6 * 3600);

		$job = $this->buildByName(AdvisoryRefreshJob::class, [
			'time' => $time,
			'advisoryService' => $service,
			'advisoryNotifier' => $notifier ?? $this->createMock(AdvisoryNotifier::class),
			'digestNotifier' => $digestNotifier ?? $this->createMock(AdvisoryDigestNotifier::class),
			'resultStore' => $store,
			'settings' => $settings,
			'logger' => $logger ?? $this->createMock(LoggerInterface::class),
		]);

		// run() is protected — this job is invoked by the cron runner, and the
		// test drives it the same way the runner does.
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	/**
	 * The whole point of moving correlation off the request path: the sweep's
	 * result has to be PERSISTED, or the endpoint has nothing to serve and the
	 * work is thrown away every six hours.
	 */
	public function testStoresTheCorrelationSnapshotWithTheSweepTime(): void {
		$correlations = ['notes' => $this->correlation('notes')];

		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn($correlations);

		$store = $this->createMock(AdvisoryResultStore::class);
		$store->expects($this->once())
			->method('save')
			->with($correlations, 1_700_000_000);

		$this->runJob($service, $store, now: 1_700_000_000);
	}

	/**
	 * Every sweep offers the correlations to the weekly digest, with the
	 * sweep time; the digest decides itself whether one is due.
	 */
	public function testOffersTheCorrelationsToTheDigestWithTheSweepTime(): void {
		$correlations = ['notes' => $this->correlation('notes')];

		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn($correlations);

		$digest = $this->createMock(AdvisoryDigestNotifier::class);
		$digest->expects($this->once())
			->method('sendIfDue')
			->with($correlations, 1_700_000_000)
			->willReturn(0);

		$this->runJob($service, $this->createMock(AdvisoryResultStore::class), now: 1_700_000_000, digestNotifier: $digest);
	}

	/**
	 * REGRESSION GUARD for the interaction that this change exists to fix.
	 *
	 * AdvisoryService's DEFAULT budget is 5s, sized for a request a user is
	 * waiting on. If the job inherited that default it would correlate only
	 * the first handful of apps and store a snapshot that looks complete —
	 * the endpoint would answer instantly and be wrong, which is worse than
	 * the hang it replaced.
	 */
	public function testSweepsWithItsOwnBudgetNotTheRequestPathDefault(): void {
		$service = $this->createMock(AdvisoryService::class);
		$service->expects($this->once())
			->method('correlateAll')
			->with($this->greaterThan(AdvisoryService::CORRELATE_ALL_BUDGET_SECONDS))
			->willReturn([]);

		$this->runJob($service, $this->createMock(AdvisoryResultStore::class));
	}

	/**
	 * Persisting comes first: notification talks to the notifications app and
	 * is the more failure-prone half. A snapshot the admin can read must
	 * survive a notifier that throws.
	 */
	public function testKeepsTheStoredSnapshotWhenNotificationFails(): void {
		$correlations = ['notes' => $this->correlation('notes')];

		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn($correlations);

		$store = $this->createMock(AdvisoryResultStore::class);
		$store->expects($this->once())->method('save')->with($correlations, 1_700_000_000);

		$notifier = $this->createMock(AdvisoryNotifier::class);
		$notifier->method('notifyNewAdvisories')->willThrowException(new \RuntimeException('notifications app is gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->runJob($service, $store, $notifier, $logger);
	}

	/**
	 * A sweep that could not reach some sources must say so. Apps whose source
	 * did not answer carry an `error`, and a UI that only renders badges for
	 * what it found cannot distinguish those from "clean".
	 */
	public function testWarnsWhenSomeAppsCouldNotBeCorrelated(): void {
		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn([
			'notes' => $this->correlation('notes'),
			'calendar' => $this->correlation('calendar', 'source did not answer'),
		]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('could not be correlated'),
				$this->callback(static fn (array $c): bool => $c['unreached'] === 1 && $c['total'] === 2),
			);

		$this->runJob($service, $this->createMock(AdvisoryResultStore::class), null, $logger);
	}

	/**
	 * A failing sweep must not take the cron runner down with it.
	 */
	public function testSwallowsAndLogsASweepFailure(): void {
		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willThrowException(new \RuntimeException('forge unreachable'));

		$store = $this->createMock(AdvisoryResultStore::class);
		$store->expects($this->never())->method('save');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->runJob($service, $store, null, $logger);
	}
}
