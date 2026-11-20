<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

require_once __DIR__ . '/../Cache/FakeSimpleFolder.php';
require_once __DIR__ . '/../Cache/FakeSimpleFile.php';
require_once __DIR__ . '/../Cache/FakeAppData.php';

use OCA\Versioniq\Service\Installer\AppBackupStore;
use OCA\Versioniq\Service\Installer\BackupSource;
use OCA\Versioniq\Tests\Unit\Service\Cache\FakeAppData;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IAppConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
 */
final class AppBackupStoreTest extends TestCase {
	private const OCT_1 = 1_759_276_800;

	private string $root;

	private FakeAppData $appData;

	private int $now = self::OCT_1;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/vq-backup-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/apps/notes/lib', 0777, true);
		file_put_contents($this->root . '/apps/notes/appinfo.xml', '<info><version>1.0.0</version></info>');
		file_put_contents($this->root . '/apps/notes/lib/Plugin.php', '<?php // old');
		$this->appData = new FakeAppData();
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	public function testCreateKeepsTheFolderAndTablesWithSizeAndKeepTime(): void {
		$tables = ['notes_items' => [['id' => 1, 'title' => 'a']]];

		$backup = $this->store(14)->create(new BackupSource('notes', $this->root . '/apps/notes', '1.0.0', 'yes'), $tables);

		$this->assertNotNull($backup);
		$this->assertSame('notes', $backup['appId']);
		$this->assertSame('1.0.0', $backup['version']);
		$this->assertSame('yes', $backup['enabled']);
		$this->assertSame(self::OCT_1, $backup['createdAt']);
		$this->assertSame(self::OCT_1 + 14 * 86400, $backup['keptUntil']);
		$this->assertSame(['notes_items'], $backup['tables']);
		$this->assertGreaterThan(strlen(json_encode($tables)), $backup['sizeBytes']);

		$folder = $this->appData->getFolder('backup-' . self::OCT_1 . '-notes');
		$this->assertSame($tables, json_decode($folder->getFile('tables.json')->getContent(), true));
		$this->assertSame($backup, json_decode($folder->getFile('meta.json')->getContent(), true));
		$this->assertStringStartsWith("PK\x03\x04", $folder->getFile('app.zip')->getContent());
	}

	public function testZeroKeepDaysMakesNoBackup(): void {
		$this->assertNull($this->store(0)->create(new BackupSource('notes', $this->root . '/apps/notes', '1.0.0', 'yes'), []));
		$this->assertSame([], $this->appData->getDirectoryListing());
	}

	public function testAMissingFolderIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('There is no app folder to back up.');

		$this->store(14)->create(new BackupSource('notes', $this->root . '/apps/missing', '1.0.0', 'yes'), []);
	}

	public function testLatestIsTheNewestKeptBackupOfThatApp(): void {
		$store = $this->store(14);
		$store->create(new BackupSource('notes', $this->root . '/apps/notes', '1.0.0', 'yes'), []);
		$this->now = self::OCT_1 + 3600;
		$store->create(new BackupSource('notes', $this->root . '/apps/notes', '1.1.0', 'no'), []);
		$store->create(new BackupSource('note', $this->root . '/apps/notes', '9.0.0', 'yes'), []);
		$this->now = self::OCT_1 + 7200;

		$this->assertSame('1.1.0', $store->latest('notes')['version'] ?? null);
		$this->assertSame('no', $store->latest('notes')['enabled'] ?? null);
		$this->assertNull($store->latest('calendar'));
	}

	public function testAnExpiredBackupIsNotOfferedAndIsPruned(): void {
		$store = $this->store(14);
		$store->create(new BackupSource('notes', $this->root . '/apps/notes', '1.0.0', 'yes'), []);
		$this->now = self::OCT_1 + 15 * 86400;
		$store->create(new BackupSource('calendar', $this->root . '/apps/notes', '2.0.0', 'yes'), []);

		$this->assertNull($store->latest('notes'));
		$this->assertSame(1, $store->prune());
		$names = array_map(static fn ($folder): string => $folder->getName(), $this->appData->getDirectoryListing());
		$this->assertSame(['backup-' . (self::OCT_1 + 15 * 86400) . '-calendar'], $names);
		$this->assertSame(0, $store->prune());
	}

	public function testRestoreFolderPutsTheBackedUpFilesBack(): void {
		$appPath = $this->root . '/apps/notes';
		$store = $this->store(14);
		$backup = $store->create(new BackupSource('notes', $appPath, '1.0.0', 'yes'), ['notes_items' => [['id' => 2]]]);
		$this->assertNotNull($backup);
		file_put_contents($appPath . '/lib/Plugin.php', '<?php // new');
		file_put_contents($appPath . '/lib/Added.php', '<?php // only in the new version');

		$store->restoreFolder($backup, $appPath);

		$this->assertSame('<?php // old', file_get_contents($appPath . '/lib/Plugin.php'));
		$this->assertFileDoesNotExist($appPath . '/lib/Added.php');
		$this->assertDirectoryDoesNotExist($appPath . '.versioniq-restore-old');
		$this->assertDirectoryDoesNotExist($appPath . '.versioniq-restore');
		$this->assertSame(['notes_items' => [['id' => 2]]], $store->tables($backup));
	}

	public function testABrokenZipLeavesTheCurrentFolderInPlace(): void {
		$appPath = $this->root . '/apps/notes';
		$store = $this->store(14);
		$backup = $store->create(new BackupSource('notes', $appPath, '1.0.0', 'yes'), []);
		$this->assertNotNull($backup);
		$this->appData->getFolder('backup-' . self::OCT_1 . '-notes')->getFile('app.zip')->putContent('not a zip');
		file_put_contents($appPath . '/lib/Plugin.php', '<?php // new');

		try {
			$store->restoreFolder($backup, $appPath);
			$this->fail('A broken backup must be refused.');
		} catch (RuntimeException $error) {
			$this->assertSame('Could not unpack the app folder backup.', $error->getMessage());
		}
		$this->assertSame('<?php // new', file_get_contents($appPath . '/lib/Plugin.php'));
	}

	private function store(int $keepDays): AppBackupStore {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('versioniq')->willReturn($this->appData);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->with('versioniq', 'backup.keep_days', 14)->willReturn($keepDays);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn (): string => tempnam($this->root, 'zip'));

		return new AppBackupStore($factory, $config, $time, $temp);
	}
}
