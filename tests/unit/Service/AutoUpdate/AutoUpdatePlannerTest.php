<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\ProposalStore;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The live plan behind `occ versioniq:auto-update --dry-run`
 * (autoupdate-proposed-updates D3, D6).
 *
 * @covers \OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall
 * @uses \OCA\Versioniq\Service\AutoUpdate\CandidateSelector
 * @uses \OCA\Versioniq\Service\AutoUpdate\ProposedUpdate
 * @uses \OCA\Versioniq\Service\Policy\Policy
 * @uses \OCA\Versioniq\Service\Pin\Pin
 * @spec openspec/changes/autoupdate-proposed-updates/specs/cli-commands/spec.md#requirement-dry-run-the-automatic-update-policy-from-the-cli
 */
final class AutoUpdatePlannerTest extends TestCase {
	private const AT = '2026-07-24T01:00:00+00:00';

	private PolicyStore&MockObject $policies;
	private PinStore&MockObject $pins;
	private InstallerService&MockObject $installer;
	private IAppManager&MockObject $apps;
	private AttemptLedger&MockObject $ledger;
	private AutoUpdateSettingsStore&MockObject $settings;
	private ProposalStore&MockObject $proposals;

	protected function setUp(): void {
		$this->policies = $this->createMock(PolicyStore::class);
		$this->pins = $this->createMock(PinStore::class);
		$this->installer = $this->createMock(InstallerService::class);
		$this->apps = $this->createMock(IAppManager::class);
		$this->ledger = $this->createMock(AttemptLedger::class);
		$this->settings = $this->createMock(AutoUpdateSettingsStore::class);
		$this->proposals = $this->createMock(ProposalStore::class);
		$this->installer->method('isManageableApp')->willReturnCallback(static fn (string $appId): bool => $appId !== 'unmanaged');
		$this->apps->method('getAppVersion')->willReturnCallback(static fn (string $appId): string => $appId === 'gone' ? '' : '2.3.0');
		$this->installer->method('getAppVersions')->willReturnCallback(static fn (string $appId): array => $appId === 'broken'
			? ['hasError' => true, 'availableVersions' => []]
			: ['hasError' => false, 'availableVersions' => [['version' => '2.3.4'], ['version' => '2.4.0'], ['version' => '3.0.0']]]);
	}

	/** @param array<string, Policy> $policies */
	private function plan(array $policies, bool $withProposals = true): array {
		$this->policies->method('all')->willReturn($policies);
		$stores = new AutoUpdateStores($this->policies, $this->pins, $this->settings, $this->ledger, $withProposals ? $this->proposals : null);
		$planner = new AutoUpdatePlanner($stores, new AutoUpdateInstall($this->installer, $this->apps, new CandidateSelector()));

		return $planner->plan(new \DateTimeImmutable(self::AT));
	}

	private static function policy(string $level, bool $requireApproval = false): Policy {
		return new Policy($level, 'admin', '2026-07-01T00:00:00+00:00', $requireApproval);
	}

	public function testTheHighestVersionWithinTheLevelWouldInstall(): void {
		$this->proposals->method('declineOf')->willReturn(null);
		$this->installer->expects(self::never())->method('installAppVersion');
		$this->ledger->expects(self::never())->method('record');

		self::assertSame([
			['action' => 'install', 'appId' => 'openregister', 'level' => 'patch', 'fromVersion' => '2.3.0', 'toVersion' => '2.3.4', 'reason' => null],
			['action' => 'install', 'appId' => 'deck', 'level' => 'minor', 'fromVersion' => '2.3.0', 'toVersion' => '2.4.0', 'reason' => null],
		], $this->plan(['openregister' => self::policy('patch'), 'mail' => self::policy('none'), 'deck' => self::policy('minor')]));
	}

	public function testEverySkipSaysWhy(): void {
		$this->pins->method('get')->willReturnCallback(static fn (string $appId): ?Pin => $appId === 'pinned' ? new Pin('2.3.0', 'admin', '2026-07-01T00:00:00+00:00') : null);
		$this->installer->expects(self::never())->method('installAppVersion');

		$reasons = array_column($this->plan([
			'pinned' => self::policy('all'),
			'unmanaged' => self::policy('all'),
			'gone' => self::policy('all'),
			'broken' => self::policy('all'),
		], false), 'reason', 'appId');

		self::assertSame(['pinned' => 'pinned', 'unmanaged' => 'not_managed', 'gone' => 'not_installed', 'broken' => 'source_error'], $reasons);
	}

	public function testAPinnedAppIsSkippedBeforeAnySourceQuery(): void {
		$this->pins->method('get')->willReturn(new Pin('2.3.0', 'admin', '2026-07-01T00:00:00+00:00'));
		$this->installer->expects(self::never())->method('getAppVersions');

		self::assertSame('pinned', $this->plan(['openregister' => self::policy('patch')])[0]['reason']);
	}

	public function testAnAttemptedVersionIsBlocked(): void {
		$this->ledger->method('hasAttempted')->with('openregister', '2.3.4')->willReturn(true);

		$row = $this->plan(['openregister' => self::policy('patch')])[0];

		self::assertSame(['skip', 'blocked', '2.3.4'], [$row['action'], $row['reason'], $row['toVersion']]);
	}

	public function testADeclinedVersionIsHeldBack(): void {
		$this->proposals->method('declineOf')->with('openregister', '2.3.4')->willReturn(['by' => 'admin']);

		self::assertSame('declined', $this->plan(['openregister' => self::policy('patch')])[0]['reason']);
	}

	/** @return array<string, array{?string, string}> */
	public static function notice(): array {
		return [
			'planned for a later opening' => ['2026-07-25T01:00:00+00:00', 'in_notice'],
			'planned for this opening' => [self::AT, 'install'],
			'never announced' => [null, 'in_notice'],
		];
	}

	/** @dataProvider notice */
	public function testANoticePeriodHoldsUntilThePlannedOpening(?string $plannedFor, string $outcome): void {
		$this->settings->method('getNoticeDays')->willReturn(14);
		$this->proposals->method('proposalFor')->willReturn($plannedFor === null ? null : new ProposedUpdate('openregister', '2.3.0', '2.3.4', 'patch', ProposedUpdate::STATUS_IN_NOTICE, '2026-07-01T00:00:00+00:00', $plannedFor));

		$row = $this->plan(['openregister' => self::policy('patch')])[0];

		self::assertSame($outcome, $row['action'] === 'install' ? 'install' : $row['reason']);
	}

	public function testApprovalIsForTheExactVersion(): void {
		$this->proposals->method('isApproved')->willReturnCallback(static fn (string $appId, string $version): bool => $version === '2.3.4');

		$rows = array_column($this->plan(['openregister' => self::policy('patch', true), 'deck' => self::policy('minor', true)]), null, 'appId');

		self::assertSame('install', $rows['openregister']['action']);
		self::assertSame(['skip', 'awaiting_approval', '2.4.0'], [$rows['deck']['action'], $rows['deck']['reason'], $rows['deck']['toVersion']]);
	}

	public function testNoVersionWithinTheLevelIsNoCandidate(): void {
		$this->apps = $this->createMock(IAppManager::class);
		$this->apps->method('getAppVersion')->willReturn('3.0.0');

		$row = $this->plan(['openregister' => self::policy('all')])[0];

		self::assertSame(['skip', 'no_candidate', null], [$row['action'], $row['reason'], $row['toVersion']]);
	}
}
