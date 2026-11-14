<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\AutoUpdate;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ versioniq:auto-update --dry-run [--json]` (autoupdate-proposed-updates D3).
 *
 * @covers \OCA\Versioniq\Command\AutoUpdate
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow
 * @uses \OCA\Versioniq\Service\AutoUpdate\CandidateSelector
 * @uses \OCA\Versioniq\Service\Policy\Policy
 * @spec openspec/changes/autoupdate-proposed-updates/specs/cli-commands/spec.md#requirement-dry-run-the-automatic-update-policy-from-the-cli
 */
final class AutoUpdateTest extends TestCase {
	private InstallerService&MockObject $installer;
	private AttemptLedger&MockObject $ledger;

	private function tester(bool $enabled, string $now = '2026-07-23T14:00:00+00:00'): CommandTester {
		$policies = $this->createMock(PolicyStore::class);
		$policies->method('all')->willReturn(['openregister' => new Policy('patch', 'admin', '2026-07-01T00:00:00+00:00')]);
		$settings = $this->createMock(AutoUpdateSettingsStore::class);
		$settings->method('isEnabled')->willReturn($enabled);
		$settings->method('getWindow')->willReturn('01:00-05:00');
		$settings->method('getTimeZoneName')->willReturn('UTC');
		$this->ledger = $this->createMock(AttemptLedger::class);
		$this->installer = $this->createMock(InstallerService::class);
		$this->installer->method('isManageableApp')->willReturn(true);
		$this->installer->method('getAppVersions')->willReturn(['hasError' => false, 'availableVersions' => [['version' => '2.3.4'], ['version' => '2.4.0']]]);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('2.3.0');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($now));

		$planner = new AutoUpdatePlanner(
			new AutoUpdateStores($policies, $this->createMock(PinStore::class), $settings, $this->ledger, null),
			new AutoUpdateInstall($this->installer, $apps, new CandidateSelector()),
		);

		return new CommandTester(new AutoUpdate($planner, $settings, $time));
	}

	public function testTheJsonPlanNamesBothVersionsAndChangesNothing(): void {
		$tester = $this->tester(true);
		$this->installer->expects(self::never())->method('installAppVersion');
		$this->ledger->expects(self::never())->method('record');

		self::assertSame(0, $tester->execute(['--dry-run' => true, '--json' => true]));
		$json = json_decode($tester->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
		self::assertSame(['autoUpdateEnabled', 'plannedFor', 'apps'], array_keys($json));
		self::assertTrue($json['autoUpdateEnabled']);
		// Outside the window, the plan is for the next opening.
		self::assertSame('2026-07-24T01:00:00+00:00', $json['plannedFor']);
		self::assertSame([['action' => 'install', 'appId' => 'openregister', 'level' => 'patch', 'fromVersion' => '2.3.0', 'toVersion' => '2.3.4', 'reason' => null]], $json['apps']);
	}

	public function testInsideTheWindowThePlanIsForNow(): void {
		$tester = $this->tester(true, '2026-07-24T02:30:00+00:00');

		self::assertSame(0, $tester->execute(['--dry-run' => true, '--json' => true]));
		self::assertSame('2026-07-24T02:30:00+00:00', json_decode($tester->getDisplay(), true)['plannedFor']);
	}

	public function testAutomationOffStillPrintsThePlanAndExits1(): void {
		$tester = $this->tester(false);

		self::assertSame(1, $tester->execute(['--dry-run' => true]));
		$display = $tester->getDisplay();
		self::assertStringContainsString('Automatic updates are off', $display);
		self::assertStringContainsString('openregister', $display);
		self::assertStringContainsString('2.3.4', $display);
	}

	public function testWithoutDryRunNothingRuns(): void {
		$tester = $this->tester(true);
		$this->installer->expects(self::never())->method('getAppVersions');
		$this->installer->expects(self::never())->method('installAppVersion');

		self::assertSame(2, $tester->execute([]));
		self::assertStringContainsString('Only --dry-run is supported', $tester->getDisplay());
	}

	public function testItSendsNoNotification(): void {
		// The command takes no notifier at all: nothing it runs can notify.
		$parameters = (new \ReflectionClass(AutoUpdate::class))->getConstructor()?->getParameters() ?? [];
		$types = array_map(static fn (\ReflectionParameter $parameter): string => (string)$parameter->getType(), $parameters);

		self::assertNotContains(AutoUpdateNotifier::class, $types);
	}
}
