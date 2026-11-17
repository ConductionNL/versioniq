<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Snapshot;

use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Snapshot\RollbackRunner;
use OCA\Versioniq\Service\Snapshot\RunMode;
use OCA\Versioniq\Service\Snapshot\SnapshotInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Running a plan (install-bulk-rollback design D5): one standard install per
 * step from the snapshot's source, downgrades acknowledged, pins moved only
 * when asked, a stop at the first failure unless told to continue, and a dry
 * run that only checks downgrades.
 *
 * @covers \OCA\Versioniq\Service\Snapshot\RollbackRunner
 * @uses \OCA\Versioniq\Service\Snapshot\SnapshotInstaller
 * @uses \OCA\Versioniq\Service\Snapshot\RunMode
 * @uses \OCA\Versioniq\Service\Installer\InstallRequest
 * @uses \OCA\Versioniq\Service\Installer\InstallTarget
 * @uses \OCA\Versioniq\Service\Installer\InstallOptions
 * @uses \OCA\Versioniq\Service\Installer\PinChoice
 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
 */
final class RollbackRunnerTest extends TestCase {
	/** @var list<InstallRequest> */
	private array $requests = [];

	/**
	 * @param array<string, int> $statusByApp
	 */
	private function runner(array $statusByApp = []): RollbackRunner {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('installAppVersion')->willReturnCallback(function (InstallRequest $request) use ($statusByApp): array {
			$this->requests[] = $request;
			$status = $statusByApp[$request->target->appId] ?? 200;

			return ['statusCode' => $status, 'payload' => $status === 200
				? ['orphanedMigrations' => ['Version2Date']]
				: ['category' => 'checksum_mismatch', 'message' => 'The checksum does not match']];
		});

		return new RollbackRunner(new SnapshotInstaller($installer));
	}

	/**
	 * @return array{appId: string, kind: string, from: ?string, to: ?string, sourceId: ?string, pinnedTo: ?string}
	 */
	private static function step(string $appId, string $kind, ?string $from, ?string $to, ?string $pinnedTo = null): array {
		return ['appId' => $appId, 'kind' => $kind, 'from' => $from, 'to' => $to, 'sourceId' => 'github:c/' . $appId, 'pinnedTo' => $pinnedTo];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function plan(): array {
		return [
			self::step('opencatalogi', 'downgrade', '1.8.2', '1.8.0'),
			self::step('openregister', 'downgrade', '2.3.4', '2.3.0'),
			self::step('mail', 'upgrade', '3.0.0', '3.1.0'),
			self::step('calendar', 'pinned', '4.2.0', '4.0.0', '4.2.0'),
			self::step('deck', 'unchanged', '1.0.0', '1.0.0'),
			self::step('gone', 'removed', null, '1.0.0'),
			self::step('new', 'added', '1.0.0', null),
		];
	}

	public function testEveryMoveIsInstalledInOrderFromTheSnapshotSource(): void {
		$outcomes = $this->runner()->run(self::plan(), new RunMode(false, false, false));

		$this->assertSame(['opencatalogi', 'openregister', 'mail'], array_map(static fn (InstallRequest $r): string => $r->target->appId, $this->requests));
		$this->assertSame('1.8.0', $this->requests[0]->target->version);
		$this->assertSame('github:c/opencatalogi', $this->requests[0]->target->sourceOverride);
		$this->assertTrue($this->requests[0]->options->allowDowngrade);
		$this->assertFalse($this->requests[0]->options->dryRun);
		$this->assertFalse($this->requests[2]->options->allowDowngrade, 'an upgrade needs no downgrade acknowledgement');
		$this->assertNull($this->requests[0]->pinChoice->overridePin);
		$this->assertSame(['succeeded', 'succeeded', 'succeeded', 'skipped'], array_column($outcomes, 'outcome'));
		$this->assertSame('calendar', $outcomes[3]['appId'], 'a pinned step whose pin stays is skipped, not installed');
		$this->assertSame(['succeeded' => 3, 'failed' => 0, 'skipped' => 1], RollbackRunner::counts($outcomes));
	}

	public function testPinnedStepsRunOnlyWhenThePinsMove(): void {
		$this->runner()->run(self::plan(), new RunMode(false, true, false));

		$pinned = $this->requests[3];
		$this->assertSame('calendar', $pinned->target->appId);
		$this->assertSame(InstallerService::OVERRIDE_PIN_REPIN, $pinned->pinChoice->overridePin);
		$this->assertTrue($pinned->options->allowDowngrade);
	}

	public function testTheFirstFailureStopsTheRunAndTheRestAreSkipped(): void {
		$seen = [];
		$outcomes = $this->runner(['openregister' => 502])->run(self::plan(), new RunMode(false, false, false, function (array $outcome) use (&$seen): void {
			$seen[] = $outcome['appId'] . ':' . $outcome['outcome'];
		}));

		$this->assertCount(2, $this->requests, 'the third app is not touched');
		$this->assertSame(['opencatalogi:succeeded', 'openregister:failed', 'mail:skipped', 'calendar:skipped'], $seen);
		$this->assertSame(502, $outcomes[1]['statusCode']);
		$this->assertSame('checksum_mismatch', $outcomes[1]['payload']['category']);
		$this->assertSame(['succeeded' => 1, 'failed' => 1, 'skipped' => 2], RollbackRunner::counts($outcomes));
	}

	public function testContinueGoesOnAfterAFailure(): void {
		$outcomes = $this->runner(['opencatalogi' => 502])->run(self::plan(), new RunMode(false, false, true));

		$this->assertCount(3, $this->requests);
		$this->assertSame(['failed', 'succeeded', 'succeeded', 'skipped'], array_column($outcomes, 'outcome'));
	}

	public function testADryRunChecksOnlyTheDowngrades(): void {
		$outcomes = $this->runner()->run(self::plan(), new RunMode(true, false, false));

		$this->assertSame(['opencatalogi', 'openregister'], array_map(static fn (InstallRequest $r): string => $r->target->appId, $this->requests));
		$this->assertTrue($this->requests[0]->options->dryRun);
		$this->assertSame(['checked', 'checked', 'skipped', 'skipped'], array_column($outcomes, 'outcome'));
		$this->assertSame(['Version2Date'], $outcomes[0]['payload']['orphanedMigrations']);
		$this->assertSame(['succeeded' => 0, 'failed' => 0, 'skipped' => 2], RollbackRunner::counts($outcomes));
	}

	public function testRunnableKinds(): void {
		$this->assertTrue(RollbackRunner::isRunnable(self::step('a', 'downgrade', '2', '1'), false));
		$this->assertTrue(RollbackRunner::isRunnable(self::step('a', 'upgrade', '1', '2'), false));
		$this->assertFalse(RollbackRunner::isRunnable(self::step('a', 'pinned', '2', '1', '2'), false));
		$this->assertTrue(RollbackRunner::isRunnable(self::step('a', 'pinned', '2', '1', '2'), true));
		foreach (['unchanged', 'removed', 'added'] as $kind) {
			$this->assertFalse(RollbackRunner::isRunnable(self::step('a', $kind, '1', '1'), true), $kind);
		}
	}
}
