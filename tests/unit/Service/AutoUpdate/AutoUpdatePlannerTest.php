<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateMember;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSecurityStores;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\SecurityHoldStore;
use OCA\Versioniq\Service\AutoUpdate\UpdateGroup;
use OCA\Versioniq\Service\AutoUpdate\UpdateGroupStore;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PatchPolicyStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The plan of an automatic-update run (autoupdate-run-batching D1): one member
 * per app with a policy, each saying what would install or why it will not.
 */
final class AutoUpdatePlannerTest extends TestCase {
	private PolicyStore&MockObject $policies;
	private PinStore&MockObject $pins;
	private InstallerService&MockObject $installer;
	private IAppManager&MockObject $apps;
	private AttemptLedger&MockObject $ledger;
	private LoggerInterface&MockObject $logger;
	private UpdateGroupStore&MockObject $groups;

	protected function setUp(): void {
		$this->policies = $this->createMock(PolicyStore::class);
		$this->pins = $this->createMock(PinStore::class);
		$this->installer = $this->createMock(InstallerService::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->ledger = $this->createMock(AttemptLedger::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groups = $this->createMock(UpdateGroupStore::class);
	}

	private function planner(?AutoUpdateSettingsStore $settings = null): AutoUpdatePlanner {
		return new AutoUpdatePlanner(
			new AutoUpdateStores(
				$this->policies,
				$this->pins,
				$settings ?? $this->createMock(AutoUpdateSettingsStore::class),
				$this->ledger,
				new AutoUpdateSecurityStores(
					$this->createMock(AdvisoryResultStore::class),
					$this->createMock(AdvisorySettingsStore::class),
					$this->createMock(PatchPolicyStore::class),
					$this->createMock(SecurityHoldStore::class),
				),
				$this->groups,
			),
			new AutoUpdateInstall($this->installer, $this->apps, new CandidateSelector()),
			$this->logger,
		);
	}

	private static function policy(string $level): Policy {
		return new Policy($level, 'admin', '2026-11-01T00:00:00+00:00');
	}

	/**
	 * @param array<string, array<string, mixed>> $sources Per app: the getAppVersions answer.
	 */
	private function withSources(array $sources): void {
		$this->installer->method('isManageableApp')->willReturn(true);
		$this->apps->method('getAppVersion')->willReturn('1.0.0');
		$this->installer->method('getAppVersions')->willReturnCallback(static fn (string $appId): array => $sources[$appId]);
	}

	/**
	 * @return array<string, array{0: ?string, 1: ?string, 2: ?string}> Per app: installed, candidate, reason.
	 */
	private function planned(): array {
		$out = [];
		foreach ($this->planner()->plan()->members as $member) {
			$out[$member->appId] = [$member->installedVersion, $member->candidate, $member->reason];
		}

		return $out;
	}

	public function testEveryReasonAndAnInstallableApp(): void {
		$this->policies->method('all')->willReturn([
			'ready' => self::policy(Policy::LEVEL_PATCH),
			'pinned' => self::policy(Policy::LEVEL_PATCH),
			'current' => self::policy(Policy::LEVEL_PATCH),
			'offline' => self::policy(Policy::LEVEL_PATCH),
			'failedbefore' => self::policy(Policy::LEVEL_PATCH),
			'off' => self::policy(Policy::LEVEL_NONE),
		]);
		$this->pins->method('get')->willReturnCallback(static fn (string $appId) => $appId === 'pinned' ? new Pin('1.0.0', 'admin', '2026-11-01T00:00:00+00:00') : null);
		$this->withSources([
			'ready' => ['availableVersions' => [['version' => '1.0.1'], ['version' => '2.0.0']], 'hasError' => false],
			'current' => ['availableVersions' => [['version' => '1.0.0']], 'hasError' => false],
			'offline' => ['availableVersions' => [], 'hasError' => true],
			'failedbefore' => ['availableVersions' => [['version' => '1.0.2']], 'hasError' => false],
		]);
		$this->ledger->method('hasAttempted')->willReturnCallback(static fn (string $appId, string $version): bool => $appId === 'failedbefore' && $version === '1.0.2');

		$this->assertSame(
			[
				'ready' => ['1.0.0', '1.0.1', null],
				'pinned' => [null, null, AutoUpdateMember::REASON_PINNED],
				'current' => ['1.0.0', null, AutoUpdateMember::REASON_NO_CANDIDATE],
				'offline' => ['1.0.0', null, AutoUpdateMember::REASON_SOURCE_ERROR],
				'failedbefore' => ['1.0.0', '1.0.2', AutoUpdateMember::REASON_BLOCKED],
			],
			$this->planned()
		);
	}

	public function testOnlyTheReadyAppIsInstallable(): void {
		$this->policies->method('all')->willReturn(['ready' => self::policy(Policy::LEVEL_MINOR), 'current' => self::policy(Policy::LEVEL_MINOR)]);
		$this->pins->method('get')->willReturn(null);
		$this->withSources([
			'ready' => ['availableVersions' => [['version' => '1.1.0']], 'hasError' => false],
			'current' => ['availableVersions' => [], 'hasError' => false],
		]);
		$this->ledger->method('hasAttempted')->willReturn(false);

		$installable = $this->planner()->plan()->installable();

		$this->assertCount(1, $installable);
		$this->assertSame(['ready', '1.0.0', '1.1.0'], [$installable[0]->appId, $installable[0]->installedVersion, $installable[0]->candidate]);
		$this->assertTrue($installable[0]->willInstall());
	}

	public function testAPinnedOrUnmanageableAppIsNeverLookedUp(): void {
		$this->policies->method('all')->willReturn(['pinned' => self::policy(Policy::LEVEL_PATCH), 'foreign' => self::policy(Policy::LEVEL_PATCH)]);
		$this->pins->method('get')->willReturnCallback(static fn (string $appId) => $appId === 'pinned' ? new Pin('1.0.0', 'admin', '2026-11-01T00:00:00+00:00') : null);
		$this->installer->method('isManageableApp')->willReturnCallback(static fn (string $appId): bool => $appId !== 'foreign');
		$this->apps->expects($this->never())->method('getAppVersion');
		$this->installer->expects($this->never())->method('getAppVersions');

		$this->assertSame(['pinned' => [null, null, AutoUpdateMember::REASON_PINNED]], $this->planned());
	}

	public function testAnAppThatIsNotInstalledIsLeftOutWithoutAskingItsSource(): void {
		$this->policies->method('all')->willReturn(['gone' => self::policy(Policy::LEVEL_PATCH), 'throws' => self::policy(Policy::LEVEL_PATCH)]);
		$this->pins->method('get')->willReturn(null);
		$this->installer->method('isManageableApp')->willReturn(true);
		$this->apps->method('getAppVersion')->willReturnCallback(static function (string $appId): string {
			if ($appId === 'throws') {
				throw new \RuntimeException('no such app');
			}

			return '';
		});
		$this->installer->expects($this->never())->method('getAppVersions');

		$this->assertSame([], $this->planned());
	}

	public function testAFailureWhilePlanningOneAppIsLoggedAndTheRestIsPlanned(): void {
		$this->policies->method('all')->willReturn(['broken' => self::policy(Policy::LEVEL_PATCH), 'ready' => self::policy(Policy::LEVEL_PATCH)]);
		$this->pins->method('get')->willReturnCallback(static function (string $appId) {
			if ($appId === 'broken') {
				throw new \RuntimeException('boom');
			}

			return null;
		});
		$this->withSources(['ready' => ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false]]);
		$this->ledger->method('hasAttempted')->willReturn(false);
		$this->logger->expects($this->once())->method('warning')->with('AutoUpdateJob: failed to process an app', ['appId' => 'broken', 'message' => 'boom']);

		$this->assertSame(['ready' => ['1.0.0', '1.0.1', null]], $this->planned());
	}

	public function testACapOfTwoWithThreeReadyAppsHoldsTheThird(): void {
		$this->policies->method('all')->willReturn([
			'gamma' => self::policy(Policy::LEVEL_PATCH),
			'alpha' => self::policy(Policy::LEVEL_PATCH),
			'beta' => self::policy(Policy::LEVEL_PATCH),
		]);
		$this->pins->method('get')->willReturn(null);
		$this->withSources(array_fill_keys(['alpha', 'beta', 'gamma'], ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false]));
		$this->ledger->method('hasAttempted')->willReturn(false);
		$settings = $this->createMock(AutoUpdateSettingsStore::class);
		$settings->method('getMaxPerRun')->willReturn(2);
		$settings->method('getDeferred')->willReturn([]);
		$settings->expects($this->once())->method('setDeferred')->with(['app:gamma' => '2026-11-15T02:00:00+00:00']);
		$planner = $this->planner($settings);

		$plan = $planner->plan(now: new \DateTimeImmutable('2026-11-15T02:00:00+00:00'));

		$this->assertSame(['alpha', 'beta'], array_map(static fn (AutoUpdateMember $member): string => $member->appId, $plan->installable()));
		$this->assertSame(AutoUpdateMember::REASON_LIMIT, $plan->members[2]->reason);
		$this->assertSame('gamma', $plan->members[2]->appId);
	}

	private static function group(string $name, string ...$apps): UpdateGroup {
		return new UpdateGroup(strtolower($name), $name, array_values($apps), ['by' => 'admin', 'at' => '2026-11-01T00:00:00+00:00']);
	}

	/**
	 * @return list<array{0: string, 1: ?string, 2: ?string}> In run order: app, reason, group.
	 */
	private static function runOrder(\OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlan $plan): array {
		return array_map(static fn (AutoUpdateMember $member): array => [$member->appId, $member->reason, $member->group], $plan->members);
	}

	public function testAGroupInstallsInItsStoredOrderAndCarriesItsName(): void {
		$this->policies->method('all')->willReturn([
			'alpha' => self::policy(Policy::LEVEL_PATCH),
			'opencatalogi' => self::policy(Policy::LEVEL_PATCH),
			'openregister' => self::policy(Policy::LEVEL_PATCH),
		]);
		$this->pins->method('get')->willReturn(null);
		$this->withSources(array_fill_keys(['alpha', 'opencatalogi', 'openregister'], ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false]));
		$this->ledger->method('hasAttempted')->willReturn(false);
		$this->groups->method('all')->willReturn([self::group('Registers', 'openregister', 'opencatalogi')]);

		$this->assertSame([
			['alpha', null, null],
			['openregister', null, 'Registers'],
			['opencatalogi', null, 'Registers'],
		], self::runOrder($this->planner()->plan()));
	}

	public function testAPinnedMemberHoldsTheWholeGroup(): void {
		$this->policies->method('all')->willReturn([
			'openregister' => self::policy(Policy::LEVEL_PATCH),
			'opencatalogi' => self::policy(Policy::LEVEL_PATCH),
		]);
		$this->pins->method('get')->willReturnCallback(static fn (string $appId): ?Pin => $appId === 'opencatalogi' ? new Pin('1.0.0', 'alice', '2026-11-01T00:00:00+00:00') : null);
		$this->withSources(['openregister' => ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false]]);
		$this->ledger->method('hasAttempted')->willReturn(false);
		$this->groups->method('all')->willReturn([self::group('Registers', 'openregister', 'opencatalogi')]);

		$plan = $this->planner()->plan();

		$this->assertSame([
			['openregister', AutoUpdateMember::REASON_PINNED, 'Registers'],
			['opencatalogi', AutoUpdateMember::REASON_PINNED, 'Registers'],
		], self::runOrder($plan));
		$this->assertSame([], $plan->installable());
	}

	public function testAFailedVersionOfOneMemberHoldsTheGroupAsBlocked(): void {
		$this->policies->method('all')->willReturn([
			'openregister' => self::policy(Policy::LEVEL_PATCH),
			'opencatalogi' => self::policy(Policy::LEVEL_PATCH),
			'third' => self::policy(Policy::LEVEL_PATCH),
		]);
		$this->pins->method('get')->willReturn(null);
		$this->withSources([
			'openregister' => ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false],
			'opencatalogi' => ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false],
			'third' => ['availableVersions' => [], 'hasError' => false],
		]);
		$this->ledger->method('hasAttempted')->willReturnCallback(static fn (string $appId): bool => $appId === 'openregister');
		$this->groups->method('all')->willReturn([self::group('Registers', 'openregister', 'opencatalogi', 'third')]);

		$this->assertSame([
			['openregister', AutoUpdateMember::REASON_BLOCKED, 'Registers'],
			['opencatalogi', AutoUpdateMember::REASON_BLOCKED, 'Registers'],
			['third', AutoUpdateMember::REASON_BLOCKED, 'Registers'],
		], self::runOrder($this->planner()->plan()));
	}

	public function testAMemberWithNoCandidateDoesNotHoldItsGroup(): void {
		$this->policies->method('all')->willReturn([
			'openregister' => self::policy(Policy::LEVEL_PATCH),
			'opencatalogi' => self::policy(Policy::LEVEL_PATCH),
		]);
		$this->pins->method('get')->willReturn(null);
		$this->withSources([
			'openregister' => ['availableVersions' => [], 'hasError' => false],
			'opencatalogi' => ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false],
		]);
		$this->ledger->method('hasAttempted')->willReturn(false);
		$this->groups->method('all')->willReturn([self::group('Registers', 'openregister', 'opencatalogi')]);

		$this->assertSame([
			['openregister', AutoUpdateMember::REASON_NO_CANDIDATE, 'Registers'],
			['opencatalogi', null, 'Registers'],
		], self::runOrder($this->planner()->plan()));
	}

	public function testAGroupOfThreeWaitsWholeForARemainingBudgetOfTwo(): void {
		$this->policies->method('all')->willReturn([
			'a1' => self::policy(Policy::LEVEL_PATCH),
			'a2' => self::policy(Policy::LEVEL_PATCH),
			'a3' => self::policy(Policy::LEVEL_PATCH),
			'solo' => self::policy(Policy::LEVEL_PATCH),
		]);
		$this->pins->method('get')->willReturn(null);
		$this->withSources(array_fill_keys(['a1', 'a2', 'a3', 'solo'], ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false]));
		$this->ledger->method('hasAttempted')->willReturn(false);
		$this->groups->method('all')->willReturn([self::group('Batch', 'a1', 'a2', 'a3')]);
		$settings = $this->createMock(AutoUpdateSettingsStore::class);
		$settings->method('getMaxPerRun')->willReturn(2);
		$settings->method('getDeferred')->willReturn([]);
		$settings->expects($this->once())->method('setDeferred')->with(['group:Batch' => '2026-11-15T02:00:00+00:00']);

		$plan = $this->planner($settings)->plan(now: new \DateTimeImmutable('2026-11-15T02:00:00+00:00'));

		$this->assertSame(['solo'], array_map(static fn (AutoUpdateMember $member): string => $member->appId, $plan->installable()));
		$this->assertSame([
			['a1', AutoUpdateMember::REASON_LIMIT, 'Batch'],
			['a2', AutoUpdateMember::REASON_LIMIT, 'Batch'],
			['a3', AutoUpdateMember::REASON_LIMIT, 'Batch'],
			['solo', null, null],
		], self::runOrder($plan));
	}

	public function testAHeldGroupGoesFirstInTheNextRun(): void {
		$this->policies->method('all')->willReturn([
			'a1' => self::policy(Policy::LEVEL_PATCH),
			'a2' => self::policy(Policy::LEVEL_PATCH),
			'solo' => self::policy(Policy::LEVEL_PATCH),
		]);
		$this->pins->method('get')->willReturn(null);
		$this->withSources(array_fill_keys(['a1', 'a2', 'solo'], ['availableVersions' => [['version' => '1.0.1']], 'hasError' => false]));
		$this->ledger->method('hasAttempted')->willReturn(false);
		$this->groups->method('all')->willReturn([self::group('Zeta', 'a1', 'a2')]);
		$settings = $this->createMock(AutoUpdateSettingsStore::class);
		$settings->method('getMaxPerRun')->willReturn(2);
		$settings->method('getDeferred')->willReturn(['group:Zeta' => '2026-11-14T02:00:00+00:00']);
		$settings->expects($this->once())->method('setDeferred')->with(['app:solo' => '2026-11-15T02:00:00+00:00']);

		$plan = $this->planner($settings)->plan(now: new \DateTimeImmutable('2026-11-15T02:00:00+00:00'));

		$this->assertSame(['a1', 'a2'], array_map(static fn (AutoUpdateMember $member): string => $member->appId, $plan->installable()));
		$this->assertSame(AutoUpdateMember::REASON_LIMIT, $plan->members[2]->reason);
	}
}
