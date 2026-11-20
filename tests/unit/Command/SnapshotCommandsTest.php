<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\InstallExitCode;
use OCA\Versioniq\Command\ListSnapshots;
use OCA\Versioniq\Command\TakeSnapshot;
use OCA\Versioniq\Service\Snapshot\InstanceSnapshot;
use OCA\Versioniq\Service\Snapshot\SnapshotStore;
use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ versioniq:snapshot`, `occ versioniq:snapshots` and the shared install
 * exit codes (install-bulk-rollback design D5).
 *
 * @covers \OCA\Versioniq\Command\TakeSnapshot
 * @covers \OCA\Versioniq\Command\ListSnapshots
 * @covers \OCA\Versioniq\Command\InstallExitCode
 * @uses \OCA\Versioniq\Service\Snapshot\InstanceSnapshot
 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
 */
final class SnapshotCommandsTest extends TestCase {
	public function testTakeSnapshotPassesTheNameAndReportsTheId(): void {
		$taker = $this->createMock(SnapshotTaker::class);
		$taker->expects($this->once())->method('take')->with('manual', 'system', 'Before the October upgrade')
			->willReturn(new InstanceSnapshot(['id' => 's1', 'takenAt' => '2026-09-26T00:00:00+00:00', 'takenBy' => 'system', 'reason' => 'manual'], [
				'openregister' => ['version' => '2.3.0', 'sourceId' => null, 'enabled' => true],
			]));
		$tester = new CommandTester(new TakeSnapshot($taker));

		$this->assertSame(0, $tester->execute(['--name' => 'Before the October upgrade']));
		$this->assertStringContainsString('Snapshot s1 taken of 1 apps.', $tester->getDisplay());
	}

	public function testTakeSnapshotWithoutANamePassesNull(): void {
		$taker = $this->createMock(SnapshotTaker::class);
		$taker->expects($this->once())->method('take')->with('manual', 'system', null)
			->willReturn(new InstanceSnapshot(['id' => 's1', 'takenAt' => '2026-09-26T00:00:00+00:00', 'takenBy' => 'system', 'reason' => 'manual'], []));

		$this->assertSame(0, (new CommandTester(new TakeSnapshot($taker)))->execute([]));
	}

	public function testAFullNamedCapExitsTwo(): void {
		$taker = $this->createMock(SnapshotTaker::class);
		$taker->method('take')->willThrowException(new \OverflowException('At most 20 named snapshots are kept; delete one first'));
		$tester = new CommandTester(new TakeSnapshot($taker));

		$this->assertSame(2, $tester->execute(['--name' => 'x']));
		$this->assertStringContainsString('At most 20 named snapshots', $tester->getDisplay());
	}

	public function testListSnapshotsPrintsATableOrJson(): void {
		$index = [
			['id' => 's2', 'takenAt' => '2026-09-25T10:00:00+00:00', 'reason' => 'manual', 'name' => 'Before the October upgrade', 'appCount' => 4],
			['id' => 's1', 'takenAt' => '2026-09-20T10:00:00+00:00', 'reason' => 'daily', 'name' => null, 'appCount' => 3],
		];
		$store = $this->createMock(SnapshotStore::class);
		$store->method('index')->willReturn($index);

		$table = new CommandTester(new ListSnapshots($store));
		$this->assertSame(0, $table->execute([]));
		$this->assertMatchesRegularExpression('/s2\s+\|\s+2026-09-25T10:00:00\+00:00\s+\|\s+manual\s+\|\s+Before the October upgrade\s+\|\s+4/', $table->getDisplay());

		$json = new CommandTester(new ListSnapshots($store));
		$this->assertSame(0, $json->execute(['--json' => true]));
		$this->assertSame($index, json_decode($json->getDisplay(), true));
	}

	/**
	 * @return iterable<string, array{0: int, 1: array<string, mixed>, 2: int}>
	 */
	public static function outcomes(): iterable {
		yield 'ok' => [200, [], 0];
		yield 'downgrade refused' => [409, ['category' => 'downgrade_guard'], 3];
		yield 'checksum' => [502, ['category' => 'checksum_mismatch'], 6];
		yield 'finalize' => [500, ['category' => 'finalize'], 8];
		yield 'untrusted source' => [403, [], 9];
		yield 'not found' => [404, [], 2];
		yield 'unclassified' => [500, ['category' => 'something_else'], 1];
	}

	/**
	 * @dataProvider outcomes
	 * @param array<string, mixed> $payload
	 */
	public function testInstallExitCodes(int $status, array $payload, int $expected): void {
		$this->assertSame($expected, InstallExitCode::for($status, $payload));
	}
}
