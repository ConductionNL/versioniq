<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateMember;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
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

	protected function setUp(): void {
		$this->policies = $this->createMock(PolicyStore::class);
		$this->pins = $this->createMock(PinStore::class);
		$this->installer = $this->createMock(InstallerService::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->ledger = $this->createMock(AttemptLedger::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function planner(): AutoUpdatePlanner {
		return new AutoUpdatePlanner(
			new AutoUpdateStores($this->policies, $this->pins, $this->createMock(AutoUpdateSettingsStore::class), $this->ledger),
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
}
