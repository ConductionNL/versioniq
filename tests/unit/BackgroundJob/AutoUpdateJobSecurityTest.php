<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

require_once __DIR__ . '/../Support/InstallArgs.php';

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\BackgroundJob\AutoUpdateJob;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\InstallArgs;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The nightly job on the `security` level (autoupdate-security-first D1, D2).
 *
 * @covers \OCA\Versioniq\BackgroundJob\AutoUpdateJob
 * @uses \OCA\Versioniq\Service\AutoUpdate\SecurityCandidate
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall
 * @uses \OCA\Versioniq\Service\Policy\Policy
 * @uses \OCA\Versioniq\Service\Pin\Pin
 * @uses \OCA\Versioniq\Service\Installer\InstallRequest
 * @uses \OCA\Versioniq\Service\Installer\InstallTarget
 * @uses \OCA\Versioniq\Service\Installer\InstallOptions
 * @uses \OCA\Versioniq\Service\Installer\PinChoice
 * @uses \OCA\Versioniq\BackgroundJob\JobRuntime
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
final class AutoUpdateJobSecurityTest extends TestCase {
	use BuildsByParameterName;

	/** The run's "now": 02:00, inside the 01:00-05:00 window. */
	private const NOW = 1_784_772_000;

	/** The advisory interval: six hours. */
	private const INTERVAL = 6 * 3600;

	/** @var array<string, MockObject> */
	private array $mocks;

	protected function setUp(): void {
		$this->mocks = [
			'policyStore' => $this->createMock(PolicyStore::class),
			'pinStore' => $this->createMock(PinStore::class),
			'installerService' => $this->createMock(InstallerService::class),
			'appManager' => $this->createMock(IAppManager::class),
			'candidateSelector' => $this->createMock(CandidateSelector::class),
			'attemptLedger' => $this->createMock(AttemptLedger::class),
			'settingsStore' => $this->createMock(AutoUpdateSettingsStore::class),
			'advisoryResults' => $this->createMock(AdvisoryResultStore::class),
			'advisorySettings' => $this->createMock(AdvisorySettingsStore::class),
			'notifier' => $this->createMock(AutoUpdateNotifier::class),
			'logger' => $this->createMock(LoggerInterface::class),
		];
		$this->mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$this->mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$this->mocks['advisorySettings']->method('getIntervalSeconds')->willReturn(self::INTERVAL);
		$this->mocks['policyStore']->method('all')->willReturn([
			'openregister' => new Policy(Policy::LEVEL_SECURITY, 'admin', '2026-07-01T00:00:00+00:00'),
		]);
		$this->mocks['installerService']->method('isManageableApp')->willReturn(true);
		$this->mocks['appManager']->method('getAppVersion')->willReturn('2.3.4');
		$this->mocks['attemptLedger']->method('hasAttempted')->willReturn(false);
		// The level selector is never asked for a `security` app.
		$this->mocks['candidateSelector']->expects($this->never())->method('select');
	}

	/**
	 * The stored advisory snapshot, checked `$age` seconds before the run.
	 *
	 * @param array<string, mixed> $rows The rows by app id.
	 */
	private function snapshot(array $rows, int $age = 3600): void {
		$this->mocks['advisoryResults']->expects($this->once())
			->method('read')
			->willReturn(['advisories' => $rows, 'checkedAt' => self::NOW - $age]);
	}

	/** The source lists `$versions`. */
	private function source(array $versions): void {
		$this->mocks['installerService']->method('getAppVersions')->willReturn([
			'availableVersions' => array_map(static fn (string $version): array => ['version' => $version], $versions),
			'hasError' => false,
		]);
	}

	private function runJob(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-23T02:00:00'));
		$time->method('getTime')->willReturn(self::NOW);

		$named = $this->mocks;
		$named['time'] = $time;
		$job = $this->buildByName(AutoUpdateJob::class, $named);
		(new ReflectionMethod(AutoUpdateJob::class, 'run'))->invoke($job, null);
	}

	/** @return array<string, mixed> */
	private static function affected(): array {
		return [
			'appId' => 'openregister',
			'installedVersion' => '2.3.4',
			'state' => AdvisoryService::STATE_VULNERABLE,
			'advisories' => [['id' => 'GHSA-1', 'severity' => 'high', 'summary' => 'XSS']],
			'recommendedVersion' => '2.3.5',
			'error' => null,
		];
	}

	public function testAnAffectedAppInstallsTheRecommendedFix(): void {
		$this->mocks['pinStore']->method('get')->willReturn(null);
		$this->snapshot(['openregister' => self::affected()]);
		$this->source(['2.3.5', '2.4.0', '3.0.0']);
		$this->mocks['installerService']->expects($this->once())
			->method('installAppVersion')
			->with(...InstallArgs::expected('openregister', '2.3.5', false, null, null, false, false, false, false))
			->willReturn(['statusCode' => 200, 'payload' => ['installedVersion' => '2.3.5']]);
		$this->mocks['attemptLedger']->expects($this->once())
			->method('record')
			->with('openregister', '2.3.5', AttemptLedger::OUTCOME_SUCCESS, $this->isType('string'));
		$this->mocks['notifier']->expects($this->once())->method('notifySuccess')->with('openregister', '2.3.4', '2.3.5');

		$this->runJob();
	}

	public function testAnUnaffectedAppInstallsNothing(): void {
		$this->mocks['pinStore']->method('get')->willReturn(null);
		$this->snapshot(['openregister' => ['state' => AdvisoryService::STATE_NONE, 'recommendedVersion' => null]]);
		$this->source(['2.3.5', '2.4.0']);
		$this->mocks['installerService']->expects($this->never())->method('installAppVersion');

		$this->runJob();
	}

	public function testAStaleSnapshotInstallsNothingAndSaysWhy(): void {
		$this->mocks['pinStore']->method('get')->willReturn(null);
		$this->snapshot(['openregister' => self::affected()], 2 * self::INTERVAL + 1);
		$this->source(['2.3.5']);
		$this->mocks['installerService']->expects($this->never())->method('installAppVersion');
		$this->mocks['logger']->expects($this->once())
			->method('info')
			->with('AutoUpdateJob: no security fix installed', ['appId' => 'openregister', 'reason' => 'stale']);

		$this->runJob();
	}

	public function testAPinnedAffectedAppIsSkippedWithoutASourceQuery(): void {
		$this->mocks['pinStore']->method('get')->willReturn(new Pin('2.3.4', 'alice', '2026-07-01T00:00:00+00:00'));
		$this->snapshot(['openregister' => self::affected()]);
		$this->mocks['installerService']->expects($this->never())->method('getAppVersions');
		$this->mocks['installerService']->expects($this->never())->method('installAppVersion');

		$this->runJob();
	}
}
