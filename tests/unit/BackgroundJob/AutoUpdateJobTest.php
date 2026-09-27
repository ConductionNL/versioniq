<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\BackgroundJob\AutoUpdateJob;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class AutoUpdateJobTest extends TestCase {
	private function timeFactory(string $now = '2026-07-23T02:00:00'): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($now));

		return $time;
	}

	private function runJob(AutoUpdateJob $job): void {
		$method = new ReflectionMethod(AutoUpdateJob::class, 'run');
		$method->invoke($job, null);
	}

	/**
	 * @return array{policyStore: PolicyStore, pinStore: PinStore, installerService: InstallerService, appManager: IAppManager, candidateSelector: CandidateSelector, attemptLedger: AttemptLedger, settingsStore: AutoUpdateSettingsStore, notifier: AutoUpdateNotifier, logger: LoggerInterface}
	 */
	private function mocks(): array {
		return [
			'policyStore' => $this->createMock(PolicyStore::class),
			'pinStore' => $this->createMock(PinStore::class),
			'installerService' => $this->createMock(InstallerService::class),
			'appManager' => $this->createMock(IAppManager::class),
			'candidateSelector' => $this->createMock(CandidateSelector::class),
			'attemptLedger' => $this->createMock(AttemptLedger::class),
			'settingsStore' => $this->createMock(AutoUpdateSettingsStore::class),
			'notifier' => $this->createMock(AutoUpdateNotifier::class),
			'logger' => $this->createMock(LoggerInterface::class),
		];
	}

	/**
	 * @param array<string, mixed> $mocks
	 */
	private function buildJob(array $mocks, ITimeFactory $time): AutoUpdateJob {
		return new AutoUpdateJob(
			$time,
			$mocks['policyStore'],
			$mocks['pinStore'],
			$mocks['installerService'],
			$mocks['appManager'],
			$mocks['candidateSelector'],
			$mocks['attemptLedger'],
			$mocks['settingsStore'],
			$mocks['notifier'],
			$mocks['logger'],
		);
	}

	public function testDisabledIsANoOpWithNoSourceQueries(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(false);
		$mocks['policyStore']->expects($this->never())->method('all');
		$mocks['installerService']->expects($this->never())->method('getAppVersions');

		$job = $this->buildJob($mocks, $this->timeFactory());
		$this->runJob($job);
	}

	public function testOutsideTheWindowIsANoOpWithNoSourceQueries(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['policyStore']->expects($this->never())->method('all');
		$mocks['installerService']->expects($this->never())->method('getAppVersions');

		// 13:00 is outside the 01:00-05:00 window.
		$job = $this->buildJob($mocks, $this->timeFactory('2026-07-23T13:00:00'));
		$this->runJob($job);
	}

	public function testPinnedAppIsSkippedWithoutASourceQuery(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['policyStore']->method('all')->willReturn([
			'openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-07-01T00:00:00+00:00'),
		]);
		$mocks['pinStore']->method('get')->willReturn(new Pin('2.3.0', 'alice', '2026-07-01T00:00:00+00:00'));
		$mocks['installerService']->expects($this->never())->method('getAppVersions');
		$mocks['installerService']->expects($this->never())->method('installAppVersion');

		$job = $this->buildJob($mocks, $this->timeFactory());
		$this->runJob($job);
	}

	public function testUnmanageableAppIsSkipped(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['policyStore']->method('all')->willReturn([
			'versioniq' => new Policy(Policy::LEVEL_ALL, 'admin', '2026-07-01T00:00:00+00:00'),
		]);
		$mocks['pinStore']->method('get')->willReturn(null);
		$mocks['installerService']->method('isManageableApp')->willReturn(false);
		$mocks['installerService']->expects($this->never())->method('getAppVersions');

		$job = $this->buildJob($mocks, $this->timeFactory());
		$this->runJob($job);
	}

	public function testPolicyLevelNoneIsNeverProcessed(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['policyStore']->method('all')->willReturn([
			'openregister' => new Policy(Policy::LEVEL_NONE, 'admin', '2026-07-01T00:00:00+00:00'),
		]);
		$mocks['pinStore']->expects($this->never())->method('get');

		$job = $this->buildJob($mocks, $this->timeFactory());
		$this->runJob($job);
	}

	public function testNoQualifyingCandidateInstallsNothing(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['policyStore']->method('all')->willReturn([
			'openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-07-01T00:00:00+00:00'),
		]);
		$mocks['pinStore']->method('get')->willReturn(null);
		$mocks['installerService']->method('isManageableApp')->willReturn(true);
		$mocks['appManager']->method('getAppVersion')->willReturn('2.3.0');
		$mocks['installerService']->method('getAppVersions')->willReturn([
			'availableVersions' => [['version' => '2.3.0'], ['version' => '2.4.0']],
			'hasError' => false,
		]);
		$mocks['candidateSelector']->method('select')->with('2.3.0', ['2.3.0', '2.4.0'], Policy::LEVEL_PATCH)->willReturn(null);
		$mocks['installerService']->expects($this->never())->method('installAppVersion');
		$mocks['notifier']->expects($this->never())->method('notifySuccess');
		$mocks['notifier']->expects($this->never())->method('notifyFailure');

		$job = $this->buildJob($mocks, $this->timeFactory());
		$this->runJob($job);
	}

	public function testAlreadyAttemptedCandidateIsNotReattempted(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['policyStore']->method('all')->willReturn([
			'openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-07-01T00:00:00+00:00'),
		]);
		$mocks['pinStore']->method('get')->willReturn(null);
		$mocks['installerService']->method('isManageableApp')->willReturn(true);
		$mocks['appManager']->method('getAppVersion')->willReturn('2.3.0');
		$mocks['installerService']->method('getAppVersions')->willReturn([
			'availableVersions' => [['version' => '2.3.4']],
			'hasError' => false,
		]);
		$mocks['candidateSelector']->method('select')->willReturn('2.3.4');
		$mocks['attemptLedger']->method('hasAttempted')->with('openregister', '2.3.4')->willReturn(true);
		$mocks['installerService']->expects($this->never())->method('installAppVersion');

		$job = $this->buildJob($mocks, $this->timeFactory());
		$this->runJob($job);
	}

	public function testSuccessfulInstallRecordsTheAttemptAndNotifies(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['policyStore']->method('all')->willReturn([
			'openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-07-01T00:00:00+00:00'),
		]);
		$mocks['pinStore']->method('get')->willReturn(null);
		$mocks['installerService']->method('isManageableApp')->willReturn(true);
		$mocks['appManager']->method('getAppVersion')->willReturn('2.3.0');
		$mocks['installerService']->method('getAppVersions')->willReturn([
			'availableVersions' => [['version' => '2.3.4']],
			'hasError' => false,
		]);
		$mocks['candidateSelector']->method('select')->willReturn('2.3.4');
		$mocks['attemptLedger']->method('hasAttempted')->willReturn(false);
		$mocks['installerService']->expects($this->once())
			->method('installAppVersion')
			->with('openregister', '2.3.4', false, null, null, false, false, false, false)
			->willReturn([
				'statusCode' => 200,
				'payload' => ['installedVersion' => '2.3.4'],
			]);
		$mocks['attemptLedger']->expects($this->once())
			->method('record')
			->with('openregister', '2.3.4', AttemptLedger::OUTCOME_SUCCESS, $this->isType('string'));
		$mocks['notifier']->expects($this->once())
			->method('notifySuccess')
			->with('openregister', '2.3.0', '2.3.4');
		$mocks['notifier']->expects($this->never())->method('notifyFailure');

		$job = $this->buildJob($mocks, $this->timeFactory());
		$this->runJob($job);
	}

	public function testFailedInstallRecordsTheAttemptAndNotifiesWithTheClassification(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['policyStore']->method('all')->willReturn([
			'openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-07-01T00:00:00+00:00'),
		]);
		$mocks['pinStore']->method('get')->willReturn(null);
		$mocks['installerService']->method('isManageableApp')->willReturn(true);
		$mocks['appManager']->method('getAppVersion')->willReturn('2.3.0');
		$mocks['installerService']->method('getAppVersions')->willReturn([
			'availableVersions' => [['version' => '2.3.4']],
			'hasError' => false,
		]);
		$mocks['candidateSelector']->method('select')->willReturn('2.3.4');
		$mocks['attemptLedger']->method('hasAttempted')->willReturn(false);
		$mocks['installerService']->method('installAppVersion')->willReturn([
			'statusCode' => 422,
			'payload' => ['category' => 'checksum_mismatch', 'hint' => 'The downloaded archive failed its integrity check.'],
		]);
		$mocks['attemptLedger']->expects($this->once())
			->method('record')
			->with('openregister', '2.3.4', AttemptLedger::OUTCOME_FAILURE, $this->isType('string'));
		$mocks['notifier']->expects($this->once())
			->method('notifyFailure')
			->with('openregister', '2.3.4', 'checksum_mismatch', 'The downloaded archive failed its integrity check.');
		$mocks['notifier']->expects($this->never())->method('notifySuccess');

		$job = $this->buildJob($mocks, $this->timeFactory());
		$this->runJob($job);
	}

	public function testASweepFailureOnOneAppDoesNotBlockTheRest(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['policyStore']->method('all')->willReturn([
			'broken' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-07-01T00:00:00+00:00'),
			'fine' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-07-01T00:00:00+00:00'),
		]);
		$mocks['pinStore']->method('get')->willReturnCallback(function (string $appId) {
			if ($appId === 'broken') {
				throw new \RuntimeException('boom');
			}

			return null;
		});
		$mocks['installerService']->method('isManageableApp')->willReturn(true);
		$mocks['appManager']->method('getAppVersion')->willReturn('1.0.0');
		$mocks['installerService']->method('getAppVersions')->willReturn([
			'availableVersions' => [],
			'hasError' => false,
		]);
		$mocks['candidateSelector']->method('select')->willReturn(null);

		$logger = $mocks['logger'];
		$logger->expects($this->once())->method('warning');

		$job = $this->buildJob($mocks, $this->timeFactory());
		$this->runJob($job);
		$this->addToAssertionCount(1);
	}

	/**
	 * A 24-hour TimedJob first picked up at 14:00 keeps running near 14:00 and
	 * can miss a 01:00-05:00 window for months (#429). The job must wake often
	 * enough that every window of an hour or more sees at least one run.
	 */
	public function testTheJobWakesAtLeastHourly(): void {
		$job = $this->buildJob($this->mocks(), $this->timeFactory());
		$interval = new \ReflectionProperty(\OCP\BackgroundJob\TimedJob::class, 'interval');

		$this->assertLessThanOrEqual(3600, $interval->getValue($job));
	}

	/**
	 * Waking every 15 minutes must not mean sweeping every 15 minutes: one
	 * sweep per window, keyed by the window and the date it opened.
	 */
	public function testASecondRunInTheSameWindowIsANoOp(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$swept = [];
		$mocks['settingsStore']->method('hasSweptWindow')->willReturnCallback(
			static function (string $key) use (&$swept): bool {
				return in_array($key, $swept, true);
			}
		);
		$mocks['settingsStore']->method('markWindowSwept')->willReturnCallback(
			static function (string $key) use (&$swept): void {
				$swept[] = $key;
			}
		);
		$mocks['policyStore']->expects($this->once())->method('all')->willReturn([]);

		$this->runJob($this->buildJob($mocks, $this->timeFactory('2026-07-23T02:00:00')));
		$this->runJob($this->buildJob($mocks, $this->timeFactory('2026-07-23T02:15:00')));

		$this->assertSame(['01:00-05:00@2026-07-23'], $swept);
	}

	public function testTheNextNightsWindowSweepsAgain(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['settingsStore']->method('hasSweptWindow')->willReturnCallback(
			static fn (string $key): bool => $key === '01:00-05:00@2026-07-22'
		);
		$mocks['policyStore']->expects($this->once())->method('all')->willReturn([]);

		$this->runJob($this->buildJob($mocks, $this->timeFactory('2026-07-23T02:00:00')));
	}

	/**
	 * The window is read in the time zone the settings name (Nextcloud's
	 * default_timezone), not in whatever zone PHP runs in (#429).
	 */
	public function testTheWindowIsComparedInTheConfiguredTimeZone(): void {
		$mocks = $this->mocks();
		$mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$mocks['settingsStore']->method('getTimeZoneName')->willReturn('Europe/Amsterdam');
		// 00:30 UTC is 02:30 in Amsterdam in July: inside the window there.
		$mocks['policyStore']->expects($this->once())->method('all')->willReturn([]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			static fn (string $when = 'now', ?\DateTimeZone $zone = null): \DateTime => (new \DateTime('2026-07-23T00:30:00', new \DateTimeZone('UTC')))
				->setTimezone($zone ?? new \DateTimeZone('UTC'))
		);

		$this->runJob($this->buildJob($mocks, $time));
	}
}
