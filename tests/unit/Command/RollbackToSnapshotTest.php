<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\RollbackToSnapshot;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Snapshot\InstanceSnapshot;
use OCA\Versioniq\Service\Snapshot\RollbackRunLog;
use OCA\Versioniq\Service\Snapshot\RollbackRunner;
use OCA\Versioniq\Service\Snapshot\SnapshotPlans;
use OCA\Versioniq\Service\Snapshot\SnapshotStore;
use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ versioniq:rollback` (install-bulk-rollback design D5): `--to`,
 * `--dry-run`, `--continue`, `--move-pins`, the confirmation, the
 * `before_rollback` snapshot, the two `bulk_rollback` rows and the exit code
 * of a failed step.
 *
 * @covers \OCA\Versioniq\Command\RollbackToSnapshot
 * @uses \OCA\Versioniq\Command\InstallExitCode
 * @uses \OCA\Versioniq\Service\Snapshot\SnapshotStore
 * @uses \OCA\Versioniq\Service\Snapshot\SnapshotTaker
 * @uses \OCA\Versioniq\Service\Snapshot\RollbackRunner
 * @uses \OCA\Versioniq\Service\Snapshot\RollbackRunLog
 * @uses \OCA\Versioniq\Service\Snapshot\InstanceSnapshot
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @uses \OCA\Versioniq\Service\Installer\InstallRequest
 * @uses \OCA\Versioniq\Service\Installer\InstallTarget
 * @uses \OCA\Versioniq\Service\Installer\InstallOptions
 * @uses \OCA\Versioniq\Service\Installer\PinChoice
 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
 */
final class RollbackToSnapshotTest extends TestCase {
	/** @var array<string, string> */
	private array $config = [];

	/** @var list<InstallRequest> */
	private array $installs = [];

	/** @var list<array{0: string, 1: string, 2: ?string}> */
	private array $audited = [];

	private SnapshotStore $store;

	/**
	 * @param array<string, int> $statusByApp
	 */
	private function tester(array $statusByApp = []): CommandTester {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => $this->config[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $lazy = false): bool {
			$this->config[$key] = $value;

			return true;
		});
		$this->store = new SnapshotStore($appConfig, $this->createMock(LoggerInterface::class));
		$this->store->save(new InstanceSnapshot(['id' => 's20', 'takenAt' => '2026-09-20T10:00:00+00:00', 'takenBy' => 'system', 'reason' => 'before_auto_update'], []));
		$this->store->save(new InstanceSnapshot(['id' => 's25', 'takenAt' => '2026-09-25T10:00:00+00:00', 'takenBy' => 'system', 'reason' => 'daily'], []));

		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([]);
		$installer->method('installAppVersion')->willReturnCallback(function (InstallRequest $request) use ($statusByApp): array {
			$this->installs[] = $request;
			$status = $statusByApp[$request->target->appId] ?? 200;

			return $status === 200
				? ['statusCode' => 200, 'payload' => ['orphanedMigrations' => $request->options->dryRun ? ['Version2003Date'] : null]]
				: ['statusCode' => $status, 'payload' => ['category' => 'checksum_mismatch', 'message' => 'The checksum does not match']];
		});
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->audited[] = [$subject->operation, $status, $message];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-09-26T12:00:00', new \DateTimeZone('UTC')));
		$plans = $this->createMock(SnapshotPlans::class);
		$plans->method('planFor')->willReturn([
			['appId' => 'opencatalogi', 'kind' => 'downgrade', 'from' => '1.8.2', 'to' => '1.8.0', 'sourceId' => 'github:c/oc', 'pinnedTo' => null],
			['appId' => 'openregister', 'kind' => 'downgrade', 'from' => '2.3.4', 'to' => '2.3.0', 'sourceId' => 'github:c/or', 'pinnedTo' => null],
			['appId' => 'mail', 'kind' => 'upgrade', 'from' => '3.0.0', 'to' => '3.1.0', 'sourceId' => null, 'pinnedTo' => null],
			['appId' => 'calendar', 'kind' => 'pinned', 'from' => '4.2.0', 'to' => '4.0.0', 'sourceId' => null, 'pinnedTo' => '4.2.0'],
		]);

		return new CommandTester(new RollbackToSnapshot(
			$this->store,
			new SnapshotTaker($installer, $this->store, $audit, $time),
			$plans,
			new RollbackRunner($installer),
			new RollbackRunLog($audit),
		));
	}

	/**
	 * @return list<string>
	 */
	private function installedApps(): array {
		return array_map(static fn (InstallRequest $request): string => $request->target->appId, $this->installs);
	}

	public function testADateUsesTheNewestSnapshotOnOrBeforeItAndRunsEveryMove(): void {
		$tester = $this->tester();

		$this->assertSame(0, $tester->execute(['--to' => '2026-09-23', '--yes' => true]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('Using snapshot s20, the newest taken on or before 2026-09-23.', $display);
		$this->assertStringContainsString('3 succeeded, 0 failed, 1 skipped.', $display);
		$this->assertSame(['opencatalogi', 'openregister', 'mail'], $this->installedApps());
		$this->assertTrue($this->installs[0]->options->allowDowngrade);
		$this->assertSame('github:c/oc', $this->installs[0]->target->sourceOverride);
		$this->assertSame(['snapshot', 'bulk_rollback', 'bulk_rollback'], array_column($this->audited, 0));
		$this->assertStringContainsString('(before_rollback)', (string)$this->audited[0][2]);
		$this->assertSame('Rollback to snapshot s20 started: 3 steps', $this->audited[1][2]);
		$this->assertSame('Rollback to snapshot s20 finished: 3 succeeded, 0 failed, 1 skipped', $this->audited[2][2]);
		$this->assertSame('before_rollback', $this->store->index()[0]['reason']);
	}

	public function testADryRunListsTheOrphanedMigrationsAndChangesNothing(): void {
		$tester = $this->tester();

		$this->assertSame(0, $tester->execute(['snapshotId' => 's20', '--dry-run' => true]));

		$this->assertStringContainsString('opencatalogi 1.8.2 -> 1.8.0: would orphan Version2003Date', $tester->getDisplay());
		$this->assertSame(['opencatalogi', 'openregister'], $this->installedApps());
		foreach ($this->installs as $install) {
			$this->assertTrue($install->options->dryRun);
		}
		$this->assertSame([], $this->audited, 'a dry run takes no snapshot and writes no run rows');
	}

	public function testAFailedStepStopsTheRunAndExitsWithItsInstallCode(): void {
		$tester = $this->tester(['openregister' => 502]);

		$this->assertSame(6, $tester->execute(['snapshotId' => 's20', '--yes' => true]));

		$this->assertSame(['opencatalogi', 'openregister'], $this->installedApps());
		$this->assertStringContainsString('openregister 2.3.4 -> 2.3.0 failed: The checksum does not match', $tester->getDisplay());
		$this->assertSame([AuditLogger::OPERATION_BULK_ROLLBACK, AuditLogger::STATUS_FAILURE, 'Rollback to snapshot s20 finished: 1 succeeded, 1 failed, 2 skipped'], $this->audited[2]);
	}

	public function testContinueGoesOnAfterAFailure(): void {
		$tester = $this->tester(['opencatalogi' => 502]);

		$this->assertSame(6, $tester->execute(['snapshotId' => 's20', '--yes' => true, '--continue' => true]));

		$this->assertSame(['opencatalogi', 'openregister', 'mail'], $this->installedApps());
	}

	public function testMovePinsIncludesThePinnedSteps(): void {
		$tester = $this->tester();

		$this->assertSame(0, $tester->execute(['snapshotId' => 's20', '--yes' => true, '--move-pins' => true]));

		$this->assertSame(['opencatalogi', 'openregister', 'mail', 'calendar'], $this->installedApps());
		$this->assertSame(InstallerService::OVERRIDE_PIN_REPIN, $this->installs[3]->pinChoice->overridePin);
	}

	public function testDecliningTheConfirmationChangesNothing(): void {
		$tester = $this->tester();
		$tester->setInputs(['n']);

		$this->assertSame(2, $tester->execute(['snapshotId' => 's20']));

		$this->assertSame([], $this->installs);
		$this->assertSame([], $this->audited);
		$this->assertStringContainsString('Cancelled; nothing changed.', $tester->getDisplay());
	}

	public function testConfirmingRunsThePlan(): void {
		$tester = $this->tester();
		$tester->setInputs(['y']);

		$this->assertSame(0, $tester->execute(['snapshotId' => 's20']));

		$this->assertCount(3, $this->installs);
	}

	public function testWithoutATerminalItNeedsYes(): void {
		$tester = $this->tester();

		$this->assertSame(2, $tester->execute(['snapshotId' => 's20'], ['interactive' => false]));

		$this->assertSame([], $this->installs);
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function badArguments(): iterable {
		yield 'neither' => [[], 'Give either a snapshot id or --to=YYYY-MM-DD.'];
		yield 'both' => [['snapshotId' => 's20', '--to' => '2026-09-23'], 'Give either a snapshot id or --to=YYYY-MM-DD.'];
		yield 'unknown id' => [['snapshotId' => 'nope'], 'Snapshot nope not found.'];
		yield 'bad date' => [['--to' => '23-09-2026'], '--to must be a date, YYYY-MM-DD.'];
		yield 'too early' => [['--to' => '2026-09-01'], 'No snapshot taken on or before 2026-09-01.'];
	}

	/**
	 * @dataProvider badArguments
	 * @param array<string, mixed> $input
	 */
	public function testBadArgumentsExitTwoAndChangeNothing(array $input, string $message): void {
		$tester = $this->tester();

		$this->assertSame(2, $tester->execute($input + ['--yes' => true]));

		$this->assertStringContainsString($message, $tester->getDisplay());
		$this->assertSame([], $this->installs);
		$this->assertSame([], $this->audited);
	}
}
