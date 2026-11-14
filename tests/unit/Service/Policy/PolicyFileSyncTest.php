<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Policy;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyChange;
use OCA\Versioniq\Service\Policy\PolicyFileContents;
use OCA\Versioniq\Service\Policy\PolicyFileSync;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 */
#[CoversClass(PolicyFileSync::class)]
#[CoversClass(PolicyChange::class)]
#[CoversClass(PolicyFileContents::class)]
#[UsesClass(Policy::class)]
#[UsesClass(AuditSubject::class)]
#[UsesClass(AuditedVersions::class)]
final class PolicyFileSyncTest extends TestCase {
	private PolicyStore&MockObject $policies;
	private AutoUpdateSettingsStore&MockObject $settings;
	private AuditLogger&MockObject $audit;
	private PolicyFileSync $sync;

	protected function setUp(): void {
		$this->policies = $this->createMock(PolicyStore::class);
		$this->policies->method('all')->willReturn([
			'openregister' => new Policy('patch', 'alice', '2026-11-01T00:00:00+00:00'),
			'calendar' => new Policy('none', 'alice', '2026-11-01T00:00:00+00:00'),
		]);
		$this->settings = $this->createMock(AutoUpdateSettingsStore::class);
		$this->settings->method('isEnabled')->willReturn(true);
		$this->settings->method('getWindow')->willReturn('01:00-05:00');
		$this->settings->method('getDefaultLevel')->willReturn('none');
		$this->audit = $this->createMock(AuditLogger::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T10:00:00+00:00'));

		$this->sync = new PolicyFileSync($this->policies, $this->settings, $this->audit, $time);
	}

	public function testExportHoldsTheStoredSettingsAndPoliciesByAppId(): void {
		$contents = $this->sync->export();

		$this->assertTrue($contents->enabled);
		$this->assertSame('01:00-05:00', $contents->window);
		$this->assertSame('none', $contents->defaultLevel);
		$this->assertSame(['calendar' => 'none', 'openregister' => 'patch'], $contents->policies);
	}

	public function testAChangedLevelIsTheOnlyDifference(): void {
		$file = new PolicyFileContents(true, '01:00-05:00', 'none', ['openregister' => 'minor', 'calendar' => 'none']);

		$changes = $this->sync->plan($file, false);

		$this->assertSame(['policies.openregister: patch -> minor'], array_map(static fn (PolicyChange $c): string => $c->describe(), $changes));
	}

	public function testUnnamedPoliciesStayUnlessPruned(): void {
		$file = new PolicyFileContents(null, null, 'patch', ['openregister' => 'patch']);

		$this->assertSame(
			['autoUpdate.defaultLevel: none -> patch'],
			array_map(static fn (PolicyChange $c): string => $c->describe(), $this->sync->plan($file, false))
		);
		$this->assertSame(
			['autoUpdate.defaultLevel: none -> patch', 'policies.calendar: none -> (removed)'],
			array_map(static fn (PolicyChange $c): string => $c->describe(), $this->sync->plan($file, true))
		);
	}

	public function testANewPolicyAndAKillSwitchChangeArePlanned(): void {
		$file = new PolicyFileContents(false, '02:00-03:00', null, ['openregister' => 'patch', 'calendar' => 'none', 'deck' => 'all']);

		$this->assertSame(
			['autoUpdate.enabled: true -> false', 'autoUpdate.window: 01:00-05:00 -> 02:00-03:00', 'policies.deck: (not set) -> all'],
			array_map(static fn (PolicyChange $c): string => $c->describe(), $this->sync->plan($file, false))
		);
	}

	public function testApplyWritesPoliciesAsOccAndAuditsEachOne(): void {
		$this->policies->expects($this->once())->method('set')->with('openregister', $this->callback(
			static fn (Policy $p): bool => $p->level === 'minor' && $p->setBy === 'occ' && $p->setAt === '2026-11-14T10:00:00+00:00'
		));
		$this->policies->expects($this->once())->method('clear')->with('calendar');
		$audited = [];
		$this->audit->expects($this->exactly(2))->method('record')->willReturnCallback(
			function (AuditSubject $subject, AuditedVersions $versions, string $status) use (&$audited): void {
				$audited[] = [$subject->actorUid, $subject->appId, $subject->operation, $subject->origin, $versions->fromVersion, $versions->toVersion, $status];
			}
		);

		$this->sync->apply([
			new PolicyChange('policies.openregister', 'patch', 'minor'),
			new PolicyChange('policies.calendar', 'none', null),
		]);

		$this->assertSame([
			['occ', 'openregister', 'policy', AuditOrigin::CLI, 'patch', 'minor', 'success'],
			['occ', 'calendar', 'policy', AuditOrigin::CLI, 'none', null, 'success'],
		], $audited);
	}

	public function testApplyWritesSettingsThroughTheSettingsStoreWithoutAnAuditRow(): void {
		$this->settings->expects($this->once())->method('setEnabled')->with(false);
		$this->settings->expects($this->once())->method('setWindow')->with('02:00-03:00');
		$this->settings->expects($this->once())->method('setDefaultLevel')->with('patch');
		$this->audit->expects($this->never())->method('record');

		$this->sync->apply([
			new PolicyChange('autoUpdate.enabled', 'true', 'false'),
			new PolicyChange('autoUpdate.window', '01:00-05:00', '02:00-03:00'),
			new PolicyChange('autoUpdate.defaultLevel', 'none', 'patch'),
		]);
	}

	public function testAChangeKnowsWhetherItIsAPolicy(): void {
		$this->assertSame('deck', (new PolicyChange('policies.deck', null, 'all'))->appId());
		$this->assertNull((new PolicyChange('autoUpdate.window', null, '01:00-02:00'))->appId());
	}
}
