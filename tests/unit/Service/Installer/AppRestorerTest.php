<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Installer\AppBackupStore;
use OCA\Versioniq\Service\Installer\AppRestorer;
use OCA\Versioniq\Service\Installer\AppTableDumper;
use OCA\Versioniq\Service\Installer\NoBackupException;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/migration-safety/spec.md#requirement-restoring-a-broken-install-puts-back-files-and-tables
 */
final class AppRestorerTest extends TestCase {
	private const BACKUP = ['appId' => 'dossiq', 'version' => '0.4.48', 'enabled' => 'yes', 'createdAt' => 1_759_276_800, 'keptUntil' => 1_760_486_400, 'sizeBytes' => 10, 'tables' => ['dossiq_cases']];

	public function testARestorePutsBackFolderTablesVersionAndEnabledStateInThatOrder(): void {
		$calls = [];
		$tables = ['dossiq_cases' => [['id' => 1]], 'dossiq_notes' => []];
		$store = $this->createMock(AppBackupStore::class);
		$store->method('latest')->with('dossiq')->willReturn(self::BACKUP);
		$store->expects($this->once())->method('restoreFolder')->with(self::BACKUP, '/apps/dossiq')
			->willReturnCallback(static function () use (&$calls): void {
				$calls[] = 'folder';
			});
		$store->method('tables')->with(self::BACKUP)->willReturn($tables);
		$dumper = $this->createMock(AppTableDumper::class);
		$dumper->expects($this->once())->method('restore')->with($tables)
			->willReturnCallback(static function () use (&$calls): int {
				$calls[] = 'tables';
				return 1;
			});
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->exactly(2))->method('setValueString')
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$calls): bool {
				$calls[] = $app . ':' . $key . '=' . $value;
				return true;
			});
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects($this->once())->method('record')->with(
			new AuditSubject('admin', 'dossiq', 'restore'),
			new AuditedVersions('0.4.50', '0.4.48', null),
			'success',
			'Restored from the backup of 2025-10-01T00:00:00+00:00.',
		);

		$result = $this->restorer($store, $dumper, $appConfig, $audit)->restore('dossiq');

		$this->assertSame(['folder', 'tables', 'dossiq:installed_version=0.4.48', 'dossiq:enabled=yes'], $calls);
		$this->assertSame([
			'appId' => 'dossiq',
			'version' => '0.4.48',
			'enabled' => 'yes',
			'tables' => 2,
			'rows' => 1,
			'backupCreatedAt' => '2025-10-01T00:00:00+00:00',
		], $result);
	}

	public function testWithoutABackupTheRestoreIsRefusedAndNothingChanges(): void {
		$store = $this->createMock(AppBackupStore::class);
		$store->method('latest')->willReturn(null);
		$store->expects($this->never())->method('restoreFolder');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueString');
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects($this->never())->method('record');

		$this->expectException(NoBackupException::class);
		$this->expectExceptionMessage('There is no backup to restore dossiq from. Roll back to an earlier version instead.');

		$this->restorer($store, $this->createMock(AppTableDumper::class), $appConfig, $audit)->restore('dossiq');
	}

	public function testAFailedTableRestoreIsAuditedAndLeavesTheVersionAlone(): void {
		$store = $this->createMock(AppBackupStore::class);
		$store->method('latest')->willReturn(self::BACKUP);
		$store->method('tables')->willReturn([]);
		$dumper = $this->createMock(AppTableDumper::class);
		$dumper->method('restore')->willThrowException(new \RuntimeException('deadlock'));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueString');
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects($this->once())->method('record')->with($this->anything(), $this->anything(), 'failure', 'deadlock');

		$this->expectExceptionMessage('deadlock');

		$this->restorer($store, $dumper, $appConfig, $audit)->restore('dossiq');
	}

	public function testAnInvalidAppIdIsRefusedBeforeAnyLookup(): void {
		$store = $this->createMock(AppBackupStore::class);
		$store->expects($this->never())->method('latest');

		$this->expectException(\InvalidArgumentException::class);

		$this->restorer($store, $this->createMock(AppTableDumper::class), $this->createMock(IAppConfig::class), $this->createMock(AuditLogger::class))->restore('../etc');
	}

	private function restorer(AppBackupStore $store, AppTableDumper $dumper, IAppConfig $appConfig, AuditLogger $audit): AppRestorer {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('dossiq')->willReturn('0.4.50');
		$appManager->method('getAppPath')->with('dossiq', true)->willReturn('/apps/dossiq');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AppRestorer($store, $dumper, $appManager, $appConfig, $audit, $session);
	}
}
