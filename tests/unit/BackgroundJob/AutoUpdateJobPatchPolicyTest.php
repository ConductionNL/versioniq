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

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\BackgroundJob\AutoUpdateJob;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSchedule;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\SecurityHoldStore;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\PatchPolicyStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Security fixes go first and the patch policy decides how each is handled
 * (autoupdate-security-first D4).
 *
 * @covers \OCA\Versioniq\BackgroundJob\AutoUpdateJob
 * @uses \OCA\Versioniq\Service\AutoUpdate\CandidateSelector
 * @uses \OCA\Versioniq\Service\AutoUpdate\SecurityCandidate
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateSecurityStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 * @uses \OCA\Versioniq\Service\Policy\PatchPolicy
 * @uses \OCA\Versioniq\Service\Policy\Policy
 * @uses \OCA\Versioniq\Service\Installer\InstallRequest
 * @uses \OCA\Versioniq\Service\Installer\InstallTarget
 * @uses \OCA\Versioniq\Service\Installer\InstallOptions
 * @uses \OCA\Versioniq\Service\Installer\PinChoice
 * @uses \OCA\Versioniq\BackgroundJob\JobRuntime
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
 */
final class AutoUpdateJobPatchPolicyTest extends TestCase {
	use BuildsByParameterName;

	private const NOW = 1_784_772_000;

	/** @var array<string, MockObject> */
	private array $mocks;

	/** @var list<string> "appId@version" in install order. */
	private array $installed = [];

	/** @var array<string, string>|null The holds the run recorded. */
	private ?array $holds = null;

	/**
	 * @param array<string, string> $levels Level by app id, in stored order.
	 * @param array<string, string> $installed Installed version by app id.
	 * @param array<string, list<string>> $available Listed versions by app id.
	 * @param array<string, mixed> $rows Advisory rows by app id.
	 */
	private function given(array $levels, array $installed, array $available, array $rows, ?PatchPolicy $patchPolicy): void {
		$this->mocks = [
			'policyStore' => $this->createMock(PolicyStore::class),
			'pinStore' => $this->createMock(PinStore::class),
			'installerService' => $this->createMock(InstallerService::class),
			'appManager' => $this->createMock(IAppManager::class),
			'attemptLedger' => $this->createMock(AttemptLedger::class),
			'settingsStore' => $this->createMock(AutoUpdateSettingsStore::class),
			'advisoryResults' => $this->createMock(AdvisoryResultStore::class),
			'advisorySettings' => $this->createMock(AdvisorySettingsStore::class),
			'patchPolicyStore' => $this->createMock(PatchPolicyStore::class),
			'securityHolds' => $this->createMock(SecurityHoldStore::class),
			'notifier' => $this->createMock(AutoUpdateNotifier::class),
			'logger' => $this->createMock(LoggerInterface::class),
		];
		$this->mocks['settingsStore']->method('isEnabled')->willReturn(true);
		$this->mocks['settingsStore']->method('getWindow')->willReturn('01:00-05:00');
		$this->mocks['settingsStore']->method('getSchedule')->willReturn(AutoUpdateSchedule::everyDay());
		$this->mocks['advisorySettings']->method('getIntervalSeconds')->willReturn(6 * 3600);
		$this->mocks['advisoryResults']->method('read')->willReturn(['advisories' => $rows, 'checkedAt' => self::NOW - 3600]);
		$this->mocks['patchPolicyStore']->expects($this->once())->method('read')->willReturn($patchPolicy);
		$this->mocks['securityHolds']->expects($this->once())->method('replace')->willReturnCallback(function (array $holds): void {
			$this->holds = $holds;
		});
		$this->mocks['policyStore']->method('all')->willReturn(array_map(
			static fn (string $level): Policy => new Policy($level, 'admin', '2026-07-01T00:00:00+00:00'),
			$levels,
		));
		$this->mocks['pinStore']->method('get')->willReturn(null);
		$this->mocks['installerService']->method('isManageableApp')->willReturn(true);
		$this->mocks['appManager']->method('getAppVersion')->willReturnCallback(static fn (string $appId): string => $installed[$appId]);
		$this->mocks['installerService']->method('getAppVersions')->willReturnCallback(static fn (string $appId): array => [
			'availableVersions' => array_map(static fn (string $version): array => ['version' => $version], $available[$appId]),
			'hasError' => false,
		]);
		$this->mocks['attemptLedger']->method('hasAttempted')->willReturn(false);
		$this->mocks['installerService']->method('installAppVersion')->willReturnCallback(function (InstallRequest $request): array {
			$this->installed[] = $request->target->appId . '@' . $request->target->version;

			return ['statusCode' => 200, 'payload' => ['installedVersion' => $request->target->version]];
		});
	}

	private function runJob(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-23T02:00:00'));
		$time->method('getTime')->willReturn(self::NOW);

		$named = $this->mocks;
		$named['time'] = $time;
		$named['candidateSelector'] = new CandidateSelector();
		$job = $this->buildByName(AutoUpdateJob::class, $named);
		(new ReflectionMethod(AutoUpdateJob::class, 'run'))->invoke($job, null);
	}

	/** @return array<string, mixed> */
	private static function affected(string $installed, string $recommended, string $severity): array {
		return [
			'installedVersion' => $installed,
			'state' => AdvisoryService::STATE_VULNERABLE,
			'advisories' => [['id' => 'GHSA-1', 'severity' => 'low', 'summary' => 'a'], ['id' => 'GHSA-2', 'severity' => $severity, 'summary' => 'b']],
			'recommendedVersion' => $recommended,
			'error' => null,
		];
	}

	private static function policy(array $security = [], array $regular = []): PatchPolicy {
		return PatchPolicy::fromArray(['security' => $security, 'regular' => $regular], 'alice', '2026-07-01T00:00:00+00:00');
	}

	public function testAnAffectedMinorAppInstallsBeforeAnUnaffectedOne(): void {
		$this->given(
			['calendar' => Policy::LEVEL_MINOR, 'openregister' => Policy::LEVEL_MINOR],
			['calendar' => '4.7.0', 'openregister' => '2.3.0'],
			['calendar' => ['4.7.1'], 'openregister' => ['2.4.0', '2.5.0']],
			['openregister' => self::affected('2.3.0', '2.4.0', 'critical')],
			self::policy(['critical' => ['deadlineDays' => 3, 'handling' => 'next_window']]),
		);

		$this->runJob();

		// The fix is the recommended 2.4.0, not the highest minor 2.5.0, and
		// it goes before calendar although calendar is stored first.
		$this->assertSame(['openregister@2.4.0', 'calendar@4.7.1'], $this->installed);
		$this->assertSame([], $this->holds);
	}

	public function testAScheduledFixWaitsForTheSecondPass(): void {
		$this->given(
			['calendar' => Policy::LEVEL_PATCH, 'openregister' => Policy::LEVEL_SECURITY],
			['calendar' => '4.7.0', 'openregister' => '2.3.0'],
			['calendar' => ['4.7.1'], 'openregister' => ['2.3.5']],
			['openregister' => self::affected('2.3.0', '2.3.5', 'medium')],
			null,
		);

		$this->runJob();

		// No stored policy: every severity is `scheduled`, so stored order holds.
		$this->assertSame(['calendar@4.7.1', 'openregister@2.3.5'], $this->installed);
	}

	public function testAManualCriticalIsLeftAlone(): void {
		$this->given(
			['openregister' => Policy::LEVEL_SECURITY, 'deck' => Policy::LEVEL_ALL],
			['openregister' => '2.3.0', 'deck' => '1.9.2'],
			['openregister' => ['2.3.5'], 'deck' => ['1.9.3', '1.10.0']],
			['openregister' => self::affected('2.3.0', '2.3.5', 'critical'), 'deck' => self::affected('1.9.2', '1.9.3', 'critical')],
			self::policy(['critical' => ['deadlineDays' => 3, 'handling' => 'manual']]),
		);

		$this->runJob();

		$this->assertSame([], $this->installed);
		$this->assertSame(['openregister' => 'manual', 'deck' => 'manual'], $this->holds);
	}

	public function testAManualMajorIsNotInstalledForAnAllApp(): void {
		$this->given(
			['deck' => Policy::LEVEL_ALL],
			['deck' => '1.9.2'],
			['deck' => ['1.9.3', '1.10.0', '2.0.0']],
			[],
			self::policy([], ['major' => 'manual']),
		);

		$this->runJob();

		$this->assertSame(['deck@1.10.0'], $this->installed);
	}

	public function testAManualPatchKindIsNeverAutomatic(): void {
		$this->given(
			['deck' => Policy::LEVEL_PATCH],
			['deck' => '1.9.2'],
			['deck' => ['1.9.3']],
			[],
			self::policy([], ['patch' => 'manual']),
		);

		$this->runJob();

		$this->assertSame([], $this->installed);
	}

	public function testAManualMajorDoesNotBlockASecurityFix(): void {
		$this->given(
			['openregister' => Policy::LEVEL_SECURITY],
			['openregister' => '2.3.0'],
			['openregister' => ['2.3.5']],
			['openregister' => self::affected('2.3.0', '2.3.5', 'high')],
			self::policy(['high' => ['deadlineDays' => 7, 'handling' => 'next_window']], ['patch' => 'manual', 'major' => 'manual']),
		);

		$this->runJob();

		$this->assertSame(['openregister@2.3.5'], $this->installed);
	}

	public function testAFixOutsideThePatchLevelFallsBackToTheLevel(): void {
		$this->given(
			['openregister' => Policy::LEVEL_PATCH],
			['openregister' => '2.3.0'],
			['openregister' => ['2.3.1', '2.4.0']],
			['openregister' => self::affected('2.3.0', '2.4.0', 'critical')],
			self::policy(['critical' => ['deadlineDays' => 3, 'handling' => 'next_window']]),
		);

		$this->runJob();

		$this->assertSame(['openregister@2.3.1'], $this->installed);
	}

	public function testASecurityAppRecordsWhyNoFixInstalled(): void {
		$this->given(
			['deck' => Policy::LEVEL_SECURITY, 'calendar' => Policy::LEVEL_SECURITY],
			['deck' => '1.9.2', 'calendar' => '4.7.0'],
			['deck' => ['1.9.3', '2.0.1'], 'calendar' => ['4.7.1']],
			['deck' => self::affected('1.9.2', '2.0.1', 'high')],
			null,
		);

		$this->runJob();

		$this->assertSame([], $this->installed);
		$this->assertSame(['deck' => 'needs_major'], $this->holds);
	}
}
