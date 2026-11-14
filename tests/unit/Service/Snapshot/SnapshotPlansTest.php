<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Snapshot;

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Snapshot\InstanceSnapshot;
use OCA\Versioniq\Service\Snapshot\RollbackPlanner;
use OCA\Versioniq\Service\Snapshot\SnapshotPlans;
use PHPUnit\Framework\TestCase;

/**
 * The plan from the instance (install-bulk-rollback design D3): the apps
 * installed now, the pins, and the moves ordered by each app's newest audit
 * row after the snapshot.
 *
 * @covers \OCA\Versioniq\Service\Snapshot\SnapshotPlans
 * @uses \OCA\Versioniq\Service\Snapshot\RollbackPlanner
 * @uses \OCA\Versioniq\Service\Snapshot\InstanceSnapshot
 * @uses \OCA\Versioniq\Service\Pin\Pin
 * @uses \OCA\Versioniq\Db\AuditEntry
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
final class SnapshotPlansTest extends TestCase {
	private static function entry(string $createdAt): AuditEntry {
		$entry = new AuditEntry();
		$entry->setCreatedAt($createdAt);

		return $entry;
	}

	public function testMovesAreOrderedByTheNewestChangeAfterTheSnapshot(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([
			['id' => 'openregister', 'installedVersion' => '2.3.4', 'state' => 'enabled'],
			['id' => 'opencatalogi', 'installedVersion' => '1.8.2', 'state' => 'enabled'],
			['id' => 'mail', 'installedVersion' => '3.1.0', 'state' => 'enabled'],
			['id' => 'calendar', 'installedVersion' => '4.2.0', 'state' => 'enabled'],
		]);
		$pins = $this->createMock(PinStore::class);
		$pins->method('all')->willReturn(['calendar' => new Pin('4.2.0', 'alice', '2026-09-01T00:00:00+00:00')]);
		$mapper = $this->createMock(AuditEntryMapper::class);
		$asked = [];
		$mapper->method('findPage')->willReturnCallback(function (?string $appId, int $limit, int $offset) use (&$asked): array {
			$asked[] = [$appId, $limit, $offset];

			return match ($appId) {
				'openregister' => [self::entry('2026-09-26 01:05:00')],
				'opencatalogi' => [self::entry('2026-09-26 01:10:00')],
				// Changed before the snapshot only: counts as unrecorded.
				'mail' => [self::entry('2026-09-20 00:00:00')],
				default => [],
			};
		});
		$snapshot = new InstanceSnapshot('s1', '2026-09-26T01:00:00+00:00', 'system', 'before_auto_update', [
			'calendar' => ['version' => '4.0.0', 'sourceId' => null, 'enabled' => true],
			'mail' => ['version' => '3.0.0', 'sourceId' => null, 'enabled' => true],
			'opencatalogi' => ['version' => '1.8.0', 'sourceId' => 'github:c/oc', 'enabled' => true],
			'openregister' => ['version' => '2.3.0', 'sourceId' => 'github:c/or', 'enabled' => true],
		]);

		$steps = (new SnapshotPlans($installer, $pins, $mapper, new RollbackPlanner()))->planFor($snapshot);

		$this->assertSame(
			[['opencatalogi', 'downgrade'], ['openregister', 'downgrade'], ['mail', 'downgrade'], ['calendar', 'pinned']],
			array_map(static fn (array $step): array => [$step['appId'], $step['kind']], $steps),
		);
		$this->assertSame('4.2.0', $steps[3]['pinnedTo']);
		$this->assertSame('github:c/oc', $steps[0]['sourceId']);
		// Only moving apps are looked up, one newest row each; never a pinned one.
		$this->assertEqualsCanonicalizing([['openregister', 1, 0], ['opencatalogi', 1, 0], ['mail', 1, 0]], $asked);
	}

	public function testNoAuditRowsLeavesTheAlphabeticalOrder(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([
			['id' => 'zeta', 'installedVersion' => '2.0.0', 'state' => 'enabled'],
			['id' => 'alpha', 'installedVersion' => '2.0.0', 'state' => 'enabled'],
		]);
		$pins = $this->createMock(PinStore::class);
		$pins->method('all')->willReturn([]);
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('findPage')->willReturn([]);
		$snapshot = new InstanceSnapshot('s1', '2026-09-26T01:00:00+00:00', 'system', 'daily', [
			'zeta' => ['version' => '1.0.0', 'sourceId' => null, 'enabled' => true],
			'alpha' => ['version' => '1.0.0', 'sourceId' => null, 'enabled' => true],
		]);

		$steps = (new SnapshotPlans($installer, $pins, $mapper, new RollbackPlanner()))->planFor($snapshot);

		$this->assertSame(['alpha', 'zeta'], array_column($steps, 'appId'));
	}
}
