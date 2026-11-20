<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\BackgroundJob\AutoUpdatePlanJob;
use OCA\Versioniq\BackgroundJob\JobRuntime;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSecurityStores;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\MinimumAgePlanner;
use OCA\Versioniq\Service\AutoUpdate\SecurityHoldStore;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PatchPolicyStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * releases-minimum-age task 2.3: the six-hourly plan snapshot.
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 */
final class AutoUpdatePlanJobTest extends TestCase {
	private PolicyStore&MockObject $policies;
	private PinStore&MockObject $pins;
	private AutoUpdateSettingsStore&MockObject $settings;
	private InstallerService&MockObject $installer;
	private IAppManager&MockObject $apps;
	private MinimumAgePlanner&MockObject $planner;
	private AutoUpdatePlanStore&MockObject $store;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->policies = $this->createMock(PolicyStore::class);
		$this->pins = $this->createMock(PinStore::class);
		$this->settings = $this->createMock(AutoUpdateSettingsStore::class);
		$this->installer = $this->createMock(InstallerService::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->planner = $this->createMock(MinimumAgePlanner::class);
		$this->store = $this->createMock(AutoUpdatePlanStore::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function job(): AutoUpdatePlanJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T12:00:00+00:00'));

		return new AutoUpdatePlanJob(
			new JobRuntime($time, $this->logger),
			new AutoUpdateStores(
				$this->policies,
				$this->pins,
				$this->settings,
				$this->createMock(AttemptLedger::class),
				new AutoUpdateSecurityStores(
					$this->createMock(AdvisoryResultStore::class),
					$this->createMock(AdvisorySettingsStore::class),
					$this->createMock(PatchPolicyStore::class),
					$this->createMock(SecurityHoldStore::class),
				),
			),
			new AutoUpdateInstall($this->installer, $this->apps, new CandidateSelector(), $this->createMock(\OCA\Versioniq\Service\Checks\CheckRunner::class), $this->createMock(\OCA\Versioniq\Service\Installer\FailureClassifier::class), $this->planner),
			$this->store,
		);
	}

	private function runJob(): void {
		$job = $this->job();
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	private static function policy(string $level, ?int $minAgeDays = null): Policy {
		return new Policy($level, 'admin', '2026-11-01T00:00:00+00:00', $minAgeDays);
	}

	/**
	 * @return array{next: ?string, heldBack: list<array<string, string>>, securityBypass: null, exception: null, eligible: list<string>, bypassed: null}
	 */
	private static function agePlan(?string $next, array $heldBack = []): array {
		return ['next' => $next, 'heldBack' => $heldBack, 'securityBypass' => null, 'exception' => null, 'eligible' => [], 'bypassed' => null];
	}

	public function testRunsEverySixHours(): void {
		$this->assertSame(6 * 3600, (new \ReflectionProperty($this->job(), 'interval'))->getValue($this->job()));
	}

	public function testPlansWithTheKillSwitchOffAndStoresTheSnapshotWithItsTime(): void {
		$this->settings->expects($this->never())->method('isEnabled');
		$this->settings->method('getMinAgeDays')->willReturn(7);
		$this->policies->method('all')->willReturn(['deck' => self::policy(Policy::LEVEL_MINOR), 'mail' => self::policy(Policy::LEVEL_PATCH, 3)]);
		$this->pins->method('get')->willReturn(null);
		$this->installer->method('isManageableApp')->willReturn(true);
		$this->apps->method('getAppVersion')->willReturn('1.0.0');
		$this->installer->method('getAppVersions')->willReturn([
			'availableVersions' => [['version' => '1.1.0', 'releasedAt' => '2026-11-13T00:00:00+00:00'], ['version' => '1.0.1']],
			'hasError' => false,
		]);
		$held = ['version' => '1.1.0', 'releasedAt' => '2026-11-13T00:00:00+00:00', 'eligibleAt' => '2026-11-20T00:00:00+00:00', 'reason' => 'minimum_age'];
		$calls = [];
		$this->planner->method('plan')->willReturnCallback(
			static function (string $appId, string $installed, array $entries, string $level, int $minAgeDays, \DateTimeImmutable $now) use (&$calls, $held): array {
				$calls[] = [$appId, $installed, $entries, $level, $minAgeDays, $now->format(DATE_ATOM)];
				return self::agePlan('1.0.1', [$held]);
			}
		);
		$saved = null;
		$this->store->expects($this->once())->method('save')->willReturnCallback(
			static function (array $plans, int $checkedAt) use (&$saved): void {
				$saved = [$plans, $checkedAt];
			}
		);

		$this->runJob();

		$entries = [['version' => '1.1.0', 'releasedAt' => '2026-11-13T00:00:00+00:00'], ['version' => '1.0.1', 'releasedAt' => null]];
		$this->assertSame([
			['deck', '1.0.0', $entries, Policy::LEVEL_MINOR, 7, '2026-11-14T12:00:00+00:00'],
			['mail', '1.0.0', $entries, Policy::LEVEL_PATCH, 3, '2026-11-14T12:00:00+00:00'],
		], $calls);
		$this->assertSame(1_790_000_000, $saved[1]);
		$this->assertSame([
			'state' => AutoUpdatePlanJob::STATE_PLANNED,
			'installedVersion' => '1.0.0',
			'minAgeDays' => 7,
			'next' => '1.0.1',
			'heldBack' => [$held],
			'securityBypass' => null,
			'exception' => null,
		], $saved[0]['deck']);
		$this->assertSame(3, $saved[0]['mail']['minAgeDays']);
	}

	public function testAPinnedAppIsListedAsPinnedWithoutASourceCall(): void {
		$this->policies->method('all')->willReturn(['deck' => self::policy(Policy::LEVEL_PATCH)]);
		$this->pins->method('get')->willReturn(new Pin('1.0.0', 'admin', '2026-11-01T00:00:00+00:00'));
		$this->installer->expects($this->never())->method('getAppVersions');
		$this->planner->expects($this->never())->method('plan');
		$this->store->expects($this->once())->method('save')->with(
			$this->callback(static fn (array $plans): bool => $plans['deck']['state'] === AutoUpdatePlanJob::STATE_PINNED && $plans['deck']['next'] === null),
			1_790_000_000
		);

		$this->runJob();
	}

	public function testOneFailingAppDoesNotStopTheRest(): void {
		$this->policies->method('all')->willReturn([
			'broken' => self::policy(Policy::LEVEL_PATCH),
			'none' => self::policy(Policy::LEVEL_NONE),
			'down' => self::policy(Policy::LEVEL_PATCH),
			'deck' => self::policy(Policy::LEVEL_PATCH),
		]);
		$this->pins->method('get')->willReturn(null);
		$this->installer->method('isManageableApp')->willReturn(true);
		$this->apps->method('getAppVersion')->willReturnCallback(static function (string $appId): string {
			if ($appId === 'broken') {
				throw new \RuntimeException('boom');
			}
			return '1.0.0';
		});
		$this->installer->method('getAppVersions')->willReturnCallback(
			static fn (string $appId): array => $appId === 'down'
				? ['hasError' => true]
				: ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false]
		);
		$this->planner->expects($this->once())->method('plan')->willReturn(self::agePlan('1.0.1'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('failed to plan an app'), ['appId' => 'broken', 'message' => 'boom']);
		$saved = null;
		$this->store->method('save')->willReturnCallback(static function (array $plans) use (&$saved): void {
			$saved = $plans;
		});

		$this->runJob();

		$this->assertSame(['broken', 'down', 'deck'], array_keys($saved));
		$this->assertSame(AutoUpdatePlanJob::STATE_FAILED, $saved['broken']['state']);
		$this->assertSame(AutoUpdatePlanJob::STATE_SOURCE_ERROR, $saved['down']['state']);
		$this->assertSame('1.0.1', $saved['deck']['next']);
	}

	public function testAnUnmanagedOrUninstalledAppIsLeftOut(): void {
		$this->policies->method('all')->willReturn(['foreign' => self::policy(Policy::LEVEL_PATCH), 'gone' => self::policy(Policy::LEVEL_PATCH)]);
		$this->pins->method('get')->willReturn(null);
		$this->installer->method('isManageableApp')->willReturnCallback(static fn (string $appId): bool => $appId !== 'foreign');
		$this->apps->method('getAppVersion')->willReturn('');
		$this->installer->expects($this->never())->method('getAppVersions');
		$this->store->expects($this->once())->method('save')->with([], 1_790_000_000);

		$this->runJob();
	}
}
