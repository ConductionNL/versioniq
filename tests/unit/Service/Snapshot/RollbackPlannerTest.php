<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Snapshot;

use OCA\Versioniq\Service\Snapshot\InstanceSnapshot;
use OCA\Versioniq\Service\Snapshot\RollbackPlanner;
use PHPUnit\Framework\TestCase;

/**
 * The rollback plan (install-bulk-rollback design D3): every step kind, the
 * order by newest change, and apps without a recorded change last in
 * alphabetical order.
 *
 * @covers \OCA\Versioniq\Service\Snapshot\RollbackPlanner
 * @uses \OCA\Versioniq\Service\Snapshot\InstanceSnapshot
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
final class RollbackPlannerTest extends TestCase {
	/**
	 * @param array<string, string> $apps Version by app id.
	 */
	private function snapshot(array $apps): InstanceSnapshot {
		return new InstanceSnapshot('s1', '2026-11-01T00:00:00+00:00', 'admin', 'manual', array_map(
			fn (string $version): array => ['version' => $version, 'sourceId' => 'forge', 'enabled' => true],
			$apps
		));
	}

	/**
	 * @param array<string, string> $apps Version by app id.
	 * @return list<array<string, mixed>>
	 */
	private function installed(array $apps): array {
		$cards = [];
		foreach ($apps as $id => $version) {
			$cards[] = ['id' => $id, 'state' => 'enabled', 'installedVersion' => $version];
		}
		$cards[] = ['id' => 'bound_only', 'state' => 'notInstalled', 'installedVersion' => null];

		return $cards;
	}

	public function testEveryKindOfStep(): void {
		$plan = (new RollbackPlanner())->plan(
			$this->snapshot(['back' => '1.0.0', 'forward' => '3.0.0', 'same' => '2.0.0', 'gone' => '1.0.0', 'pin' => '1.0.0']),
			$this->installed(['back' => '2.0.0', 'forward' => '2.5.0', 'same' => '2.0.0', 'pin' => '2.0.0', 'new' => '1.0.0']),
			['pin' => '2.0.0'],
		);

		$kinds = array_column($plan, 'kind', 'appId');
		$this->assertSame(
			['back' => 'downgrade', 'forward' => 'upgrade', 'pin' => 'pinned', 'gone' => 'removed', 'new' => 'added', 'same' => 'unchanged'],
			$kinds
		);
		$back = $plan[array_search('back', array_column($plan, 'appId'), true)];
		$this->assertSame(['appId' => 'back', 'kind' => 'downgrade', 'from' => '2.0.0', 'to' => '1.0.0', 'sourceId' => 'forge', 'pinnedTo' => null], $back);
		$this->assertNotContains('bound_only', array_column($plan, 'appId'));
	}

	public function testAPinToTheTargetIsNoObstacle(): void {
		$plan = (new RollbackPlanner())->plan($this->snapshot(['app' => '1.0.0']), $this->installed(['app' => '2.0.0']), ['app' => '1.0.0']);

		$this->assertSame('downgrade', $plan[0]['kind']);
	}

	public function testMovesAreOrderedByNewestChangeThenAlphabetically(): void {
		$plan = (new RollbackPlanner())->plan(
			$this->snapshot(['a' => '1.0.0', 'b' => '1.0.0', 'c' => '1.0.0', 'd' => '1.0.0', 'z' => '5.0.0']),
			$this->installed(['a' => '2.0.0', 'b' => '2.0.0', 'c' => '2.0.0', 'd' => '2.0.0', 'z' => '5.0.0']),
			[],
			['c' => '2026-11-03T10:00:00+00:00', 'a' => '2026-11-02T10:00:00+00:00', 'z' => '2026-11-04T00:00:00+00:00'],
		);

		$this->assertSame(['c', 'a', 'b', 'd', 'z'], array_column($plan, 'appId'));
	}
}
