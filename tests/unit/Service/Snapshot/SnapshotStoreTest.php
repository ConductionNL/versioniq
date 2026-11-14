<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Snapshot;

use OCA\Versioniq\Service\Snapshot\InstanceSnapshot;
use OCA\Versioniq\Service\Snapshot\SnapshotStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The snapshot store over an in-memory app config (install-bulk-rollback
 * design D1): write and read, lazy keys, pruning of unnamed snapshots only,
 * the named cap, and a malformed value read as absent.
 *
 * @covers \OCA\Versioniq\Service\Snapshot\SnapshotStore
 * @covers \OCA\Versioniq\Service\Snapshot\InstanceSnapshot
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
final class SnapshotStoreTest extends TestCase {
	/** @var array<string, string> */
	private array $values = [];

	/** @var list<bool> Whether each write asked for a lazy key. */
	private array $lazyWrites = [];

	private SnapshotStore $store;

	protected function setUp(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => $this->values[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false): bool {
				$this->lazyWrites[] = $lazy;
				$this->values[$key] = $value;

				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->values[$key]);
		});
		$this->store = new SnapshotStore($config, $this->createMock(LoggerInterface::class));
	}

	private function snapshot(int $n, ?string $name = null): InstanceSnapshot {
		return new InstanceSnapshot(
			sprintf('s%03d', $n),
			gmdate(DATE_ATOM, 1_700_000_000 + $n * 3600),
			'admin',
			$name === null ? 'daily' : 'manual',
			['files_x' => ['version' => '1.' . $n . '.0', 'sourceId' => 'forge', 'enabled' => true]],
			$name,
		);
	}

	public function testASnapshotIsWrittenLazilyAndReadBack(): void {
		$snapshot = InstanceSnapshot::fromInstalledApps('s1', '2026-11-01T00:00:00+00:00', 'system', 'daily', [
			['id' => 'b', 'state' => 'enabled', 'installedVersion' => '2.0.0', 'boundSourceId' => 'forge'],
			['id' => 'a', 'state' => 'disabled', 'installedVersion' => '1.0.0', 'boundSourceId' => null],
			['id' => 'c', 'state' => 'notInstalled', 'installedVersion' => null],
		]);
		$this->store->save($snapshot);

		$read = $this->store->get('s1');
		$this->assertNotNull($read);
		$this->assertSame(
			['a' => ['version' => '1.0.0', 'sourceId' => null, 'enabled' => false], 'b' => ['version' => '2.0.0', 'sourceId' => 'forge', 'enabled' => true]],
			$read->apps
		);
		$this->assertSame([['id' => 's1', 'takenAt' => '2026-11-01T00:00:00+00:00', 'reason' => 'daily', 'name' => null, 'appCount' => 2]], $this->store->index());
		$this->assertNotContains(false, $this->lazyWrites);
		$this->assertNull($this->store->get('missing'));
	}

	public function testOnlyUnnamedSnapshotsArePrunedPastTheCap(): void {
		$this->store->save($this->snapshot(0, 'before the migration'));
		for ($n = 1; $n <= SnapshotStore::UNNAMED_CAP + 2; $n++) {
			$this->store->save($this->snapshot($n));
		}

		$ids = array_column($this->store->index(), 'id');
		$this->assertCount(SnapshotStore::UNNAMED_CAP + 1, $ids);
		$this->assertContains('s000', $ids, 'the named snapshot stays');
		$this->assertNotContains('s001', $ids);
		$this->assertNotContains('s002', $ids);
		$this->assertArrayNotHasKey('snapshot.s001', $this->values);
		$this->assertSame('s062', $ids[0], 'newest first');
	}

	public function testTheNamedCapRefusesAnotherNamedSnapshot(): void {
		for ($n = 1; $n <= SnapshotStore::NAMED_CAP; $n++) {
			$this->store->save($this->snapshot($n, 'n' . $n));
		}

		$this->expectException(\OverflowException::class);
		$this->store->save($this->snapshot(99, 'one too many'));
	}

	public function testAMalformedValueReadsAsAbsent(): void {
		$this->values['snapshot.bad'] = '{not json';
		$this->values['snapshot.odd'] = json_encode(['id' => 'odd', 'takenAt' => 'x', 'takenBy' => 'y', 'reason' => 'nonsense', 'apps' => []]);
		$this->values['snapshot.index'] = '[{"id": 5}, "x"]';

		$this->assertNull($this->store->get('bad'));
		$this->assertNull($this->store->get('odd'));
		$this->assertSame([], $this->store->index());
	}

	public function testTheDatePicksTheNewestSnapshotOnOrBeforeIt(): void {
		$this->store->save($this->snapshot(1));
		$this->store->save($this->snapshot(5));

		$moment = new \DateTimeImmutable(gmdate(DATE_ATOM, 1_700_000_000 + 4 * 3600));
		$this->assertSame('s001', $this->store->latestAtOrBefore($moment)?->id);
		$this->assertNull($this->store->latestAtOrBefore(new \DateTimeImmutable('@1600000000')));
	}

	public function testDeleteRemovesTheValueAndTheIndexLine(): void {
		$this->store->save($this->snapshot(1, 'keep me'));

		$this->assertTrue($this->store->delete('s001'));
		$this->assertFalse($this->store->delete('s001'));
		$this->assertSame([], $this->store->index());
		$this->assertArrayNotHasKey('snapshot.s001', $this->values);
	}
}
