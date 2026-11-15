<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\AppBackupStore;
use OCA\Versioniq\Service\Installer\AppTableDumper;
use OCA\Versioniq\Service\Installer\InstallBackup;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
 */
final class InstallBackupTest extends TestCase {
	public function testABackupDumpsTheAppsTablesAndNamesSizeAndKeepDate(): void {
		$store = $this->createMock(AppBackupStore::class);
		$store->method('keepDays')->willReturn(14);
		$store->expects($this->once())->method('create')
			->with('notes', '/apps/notes', '1.0.0', 'yes', ['notes_items' => [['id' => 1]]])
			->willReturn(['appId' => 'notes', 'version' => '1.0.0', 'enabled' => 'yes', 'createdAt' => 1_759_276_800, 'keptUntil' => 1_760_486_400, 'sizeBytes' => 190_840_832, 'tables' => ['notes_items']]);
		$dumper = $this->createMock(AppTableDumper::class);
		$dumper->expects($this->once())->method('tablesOf')->with('/apps/notes', 'OCA\\Notes')->willReturn(['notes_items']);
		$dumper->expects($this->once())->method('dump')->with(['notes_items'])->willReturn(['notes_items' => [['id' => 1]]]);

		$result = (new InstallBackup($store, $dumper, $this->appManager(), $this->createMock(LoggerInterface::class)))->make('notes', '/apps/notes', '1.0.0', 'yes');

		$this->assertSame(['made' => true, 'sizeBytes' => 190_840_832, 'keptUntil' => '2025-10-15'], $result);
	}

	public function testZeroKeepDaysSaysNoBackupWasMadeWithoutReadingAnything(): void {
		$store = $this->createMock(AppBackupStore::class);
		$store->method('keepDays')->willReturn(0);
		$store->expects($this->never())->method('create');
		$dumper = $this->createMock(AppTableDumper::class);
		$dumper->expects($this->never())->method('tablesOf');

		$result = (new InstallBackup($store, $dumper, $this->appManager(), $this->createMock(LoggerInterface::class)))->make('notes', '/apps/notes', '1.0.0', 'yes');

		$this->assertSame(['made' => false, 'reason' => 'disabled'], $result);
	}

	public function testAFailedBackupIsLoggedAndReportedWithoutThrowing(): void {
		$store = $this->createMock(AppBackupStore::class);
		$store->method('keepDays')->willReturn(14);
		$store->method('create')->willThrowException(new \RuntimeException('disk full'));
		$dumper = $this->createMock(AppTableDumper::class);
		$dumper->method('tablesOf')->willReturn([]);
		$dumper->method('dump')->willReturn([]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('InstallBackup: the backup before the install could not be made', ['appId' => 'notes', 'message' => 'disk full']);

		$result = (new InstallBackup($store, $dumper, $this->appManager(), $logger))->make('notes', '/apps/notes', '1.0.0', 'yes');

		$this->assertSame(['made' => false, 'reason' => 'failed', 'message' => 'disk full'], $result);
	}

	private function appManager(): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppNamespace')->willReturn('OCA\\Notes');

		return $appManager;
	}
}
