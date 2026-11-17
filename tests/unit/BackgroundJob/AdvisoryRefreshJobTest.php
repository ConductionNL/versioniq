<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\BackgroundJob\AdvisoryRefreshJob;
use OCA\Versioniq\Service\Advisory\AdvisoryDigestNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryExposureTracker;
use OCA\Versioniq\Service\Advisory\AdvisoryNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Advisory\AdvisoryTriageStore;
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
		?AdvisoryExposureTracker $exposureTracker = null,
		?AdvisoryTriageStore $triageStore = null,
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
			'triageStore' => $triageStore,
			'settings' => $settings,
			'logger' => $logger ?? $this->createMock(LoggerInterface::class),
			'exposureTracker' => $exposureTracker,
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
		$store->expects($this->never())->method('saveFailure');

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
		$store->expects($this->once())->method('saveFailure')->with(1_700_000_000);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->runJob($service, $store, null, $logger);
	}

	/**
	 * The new snapshot is handed to the exposure tracker after it is saved,
	 * and a tracker failure is logged without stopping the notifications
	 * (audit-security-reports D1).
	 *
	 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md#requirement-advisory-exposure-and-server-updates-are-recorded-in-the-history
	 */
	public function testTracksExposureAfterSavingAndSurvivesItsFailure(): void {
		$correlations = ['notes' => $this->correlation('notes')];
		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn($correlations);

		$calls = [];
		$store = $this->createMock(AdvisoryResultStore::class);
		$store->method('save')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'save';
		});
		$tracker = $this->createMock(AdvisoryExposureTracker::class);
		$tracker->expects($this->once())->method('track')->with($correlations, 1_700_000_000)
			->willReturnCallback(function () use (&$calls): void {
				$calls[] = 'track';
				throw new \RuntimeException('config unavailable');
			});
		$notifier = $this->createMock(AdvisoryNotifier::class);
		$notifier->expects($this->once())->method('notifyNewAdvisories')->willReturnCallback(function () use (&$calls): int {
			$calls[] = 'notify';
			return 0;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('AdvisoryRefreshJob: advisory exposure could not be recorded', ['message' => 'config unavailable']);

		$this->runJob($service, $store, $notifier, $logger, exposureTracker: $tracker);

		$this->assertSame(['save', 'track', 'notify'], $calls);
	}

	/**
	 * An advisory never changes a version by itself: the advisory check and
	 * its notifier take no installer, policy or auto-update dependency, so the
	 * only path to an install stays AutoUpdateJob, in the window, on a policy
	 * the admin set (autoupdate-security-first D6).
	 *
	 * @spec openspec/changes/autoupdate-security-first/tasks.md#task-3.3
	 */
	public function testTheAdvisoryCheckTakesNoInstallerOrPolicyDependency(): void {
		$forbidden = [
			\OCA\Versioniq\Service\InstallerService::class,
			\OCA\Versioniq\Service\Policy\PolicyStore::class,
			\OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall::class,
			\OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores::class,
			\OCA\Versioniq\Service\AutoUpdate\CandidateSelector::class,
			\OCA\Versioniq\Service\AutoUpdate\SecurityCandidate::class,
		];

		foreach ([AdvisoryRefreshJob::class, \OCA\Versioniq\BackgroundJob\AdvisoryRefreshSteps::class, AdvisoryNotifier::class] as $class) {
			$constructor = (new \ReflectionClass($class))->getConstructor();
			$this->assertNotNull($constructor, $class);
			$types = array_map(
				static fn (\ReflectionParameter $parameter): string => (string)$parameter->getType(),
				$constructor->getParameters()
			);
			$this->assertNotEmpty($types, $class);
			foreach ($types as $type) {
				$this->assertNotContains(ltrim($type, '?'), $forbidden, $class . ' must not depend on ' . $type);
			}
		}
	}

	/**
	 * After the snapshot is saved the job stamps the triage of every advisory
	 * in it as seen and prunes the rest, keyed `{appId}|{advisoryId}`
	 * (advisories-triage, design risks).
	 */
	public function testStampsAndPrunesTheTriageAfterSaving(): void {
		$order = [];
		$correlations = [
			':server' => array_replace($this->correlation(':server'), ['advisories' => [['id' => 'GHSA-s', 'severity' => 'high', 'summary' => 'x']]]),
			'notes' => array_replace($this->correlation('notes'), ['advisories' => [['id' => 'GHSA-a', 'severity' => 'low', 'summary' => 'y'], ['id' => 'GHSA-b', 'severity' => 'low', 'summary' => 'z']]]),
		];
		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn($correlations);
		$store = $this->createMock(AdvisoryResultStore::class);
		$store->method('save')->willReturnCallback(function () use (&$order): void {
			$order[] = 'save';
		});
		$triage = $this->createMock(AdvisoryTriageStore::class);
		$triage->expects($this->once())->method('stampAndPrune')
			->with([':server|GHSA-s', 'notes|GHSA-a', 'notes|GHSA-b'], '2023-11-14T22:13:20+00:00')
			->willReturnCallback(function () use (&$order): int {
				$order[] = 'prune';

				return 0;
			});

		$this->runJob($service, $store, triageStore: $triage);

		$this->assertSame(['save', 'prune'], $order);
	}

	/**
	 * A failing prune is logged and the notifications still run.
	 */
	public function testAFailingPruneDoesNotStopTheNotifications(): void {
		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn(['notes' => $this->correlation('notes')]);
		$triage = $this->createMock(AdvisoryTriageStore::class);
		$triage->method('stampAndPrune')->willThrowException(new \JsonException('broken'));
		$notifier = $this->createMock(AdvisoryNotifier::class);
		$notifier->expects($this->once())->method('notifyNewAdvisories');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('AdvisoryRefreshJob: could not prune the advisory triage', ['message' => 'broken']);

		$this->runJob($service, $this->createMock(AdvisoryResultStore::class), $notifier, $logger, triageStore: $triage);
	}
}
