<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\ArchiveCheck;
use OCA\Versioniq\Service\Installer\ExternalInstall;
use OCA\Versioniq\Service\Installer\ExternalInstallOptions;
use OCA\Versioniq\Service\Installer\ExternalInstallRequest;
use OCA\Versioniq\Service\Installer\ExternalInstallRun;
use OCA\Versioniq\Service\Installer\InstallBackup;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\InstallSteps;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Installer\SignedInstall;
use OCA\Versioniq\Service\Installer\StagedRelease;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Exercises the real filesystem recovery primitives that underpin the
 * backup-retention safety property of both installers (task 5.4): the backup is
 * only ever removed after finalize() succeeds, and on a post-swap failure the
 * previous files are restored from it.
 *
 * The full install path is gated behind a network download + signature
 * verification and is not unit-reachable, so the orchestration-level outcomes
 * are covered in {@see InstallerServiceTest}; here we drive the actual
 * `restoreFromBackup()` / `rmdirr()` logic against a real temp directory using
 * an instance built without the constructor (the primitives touch only the
 * filesystem, not the injected services).
 */
final class InstallerRecoveryTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/appversions-recovery-' . uniqid('', true);
		mkdir($this->root, 0777, true);
	}

	protected function tearDown(): void {
		$this->removeRecursive($this->root);
		parent::tearDown();
	}

	/**
	 * @return iterable<string, array{class-string}>
	 */
	public static function installerProvider(): iterable {
		yield 'signed installer' => [SelectedReleaseInstallerService::class];
		yield 'external installer' => [ExternalReleaseInstallerService::class];
	}

	/**
	 * @dataProvider installerProvider
	 * @param class-string $installerClass
	 */
	public function testRestoreFromBackupSwapsPreviousFilesBackIntoPlace(string $installerClass): void {
		$destination = $this->root . '/app';
		$backup = $destination . '.appversion-backup';

		// "New" (failed) files currently sit at the destination…
		mkdir($destination, 0777, true);
		file_put_contents($destination . '/marker.txt', 'NEW');
		// …and the retained backup holds the previous version.
		mkdir($backup, 0777, true);
		file_put_contents($backup . '/marker.txt', 'OLD');

		$result = $this->invokeRestore($installerClass, $destination, $backup);

		self::assertTrue($result, 'restore should report success when a backup exists');
		self::assertDirectoryExists($destination);
		self::assertDirectoryDoesNotExist($backup, 'backup must be consumed by the restore');
		self::assertSame('OLD', file_get_contents($destination . '/marker.txt'), 'previous files must be back in place');
	}

	/**
	 * @dataProvider installerProvider
	 * @param class-string $installerClass
	 */
	public function testRestoreFromBackupIsNoOpWhenThereIsNoBackup(string $installerClass): void {
		$destination = $this->root . '/app';
		mkdir($destination, 0777, true);
		file_put_contents($destination . '/marker.txt', 'NEW');

		// Fresh install: no prior version, so no backup directory.
		$result = $this->invokeRestore($installerClass, $destination, null);

		self::assertFalse($result, 'restore reports false when there was no prior version to restore');
		self::assertDirectoryExists($destination, 'restore must not touch the destination when there is no backup');
	}

	/**
	 * @dataProvider installerProvider
	 * @param class-string $installerClass
	 */
	public function testRmdirrRemovesPopulatedDirectory(string $installerClass): void {
		$dir = $this->root . '/leftover';
		mkdir($dir . '/nested', 0777, true);
		file_put_contents($dir . '/a.txt', 'x');
		file_put_contents($dir . '/nested/b.txt', 'y');

		$this->invokePrivate($installerClass, 'rmdirr', [$dir]);

		self::assertDirectoryDoesNotExist($dir);
	}

	/**
	 * The signed installer backs the old app up while its folder is still in
	 * place, with the installed version and enabled value, and the result
	 * names the backup (safety-rollback-backup-and-preflight D3).
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 */
	public function testTheSignedInstallerKeepsABackupAndTheResultNamesIt(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('notes')->willReturn('1.0.0');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('notes', 'enabled', 'no')->willReturn('yes');
		$savedServer = \OC::$server;
		\OC::$server = new class([IAppManager::class => $appManager, IAppConfig::class => $appConfig]) {
			public function __construct(private array $services) {
			}

			public function get(string $id): mixed {
				return $this->services[$id];
			}
		};
		$made = ['made' => true, 'sizeBytes' => 2048, 'keptUntil' => '2026-10-15'];
		$backup = $this->createMock(InstallBackup::class);
		$backup->expects($this->once())->method('make')->with('notes', $this->root . '/notes', '1.0.0', 'yes')->willReturn($made);

		$reflection = new ReflectionClass(SelectedReleaseInstallerService::class);
		$installer = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('installBackup')->setValue($installer, $backup);
		// Built without its constructor: live progress (install-live-progress) is off.
		$reflection->getProperty('progress')->setValue($installer, null);
		try {
			$reflection->getMethod('keepBackup')->invoke($installer, 'notes', $this->root . '/notes');
		} finally {
			\OC::$server = $savedServer;
		}
		$result = $reflection->getMethod('resultEnvelope')->invoke($installer, new SignedInstall('notes', ['version' => '1.1.0'], '1.0.0', 'yes'), 'notes');

		$this->assertSame($made, $result['backup']);
	}

	public function testWithoutABackupServiceTheSignedResultHasNoBackup(): void {
		$reflection = new ReflectionClass(SelectedReleaseInstallerService::class);
		$installer = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('installBackup')->setValue($installer, null);
		$reflection->getMethod('keepBackup')->invoke($installer, 'notes', $this->root . '/notes');
		$result = $reflection->getMethod('resultEnvelope')->invoke($installer, new SignedInstall('notes', ['version' => '1.1.0'], '1.0.0', 'yes'), 'notes');

		$this->assertArrayNotHasKey('backup', $result);
	}

	/**
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 */
	public function testTheExternalInstallerKeepsABackupOfAnUpdateButNotOfAFreshInstall(): void {
		mkdir($this->root . '/notes');
		$made = ['made' => false, 'reason' => 'disabled'];
		$backup = $this->createMock(InstallBackup::class);
		$backup->expects($this->once())->method('make')->with('notes', $this->root . '/notes', '1.0.0', 'no')->willReturn($made);
		$steps = new InstallSteps(
			$this->createMock(TrustedSourceList::class),
			$this->createMock(MigrationDiffer::class),
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			backup: $backup,
		);
		$reflection = new ReflectionClass(ExternalReleaseInstallerService::class);
		$installer = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('steps')->setValue($installer, $steps);
		$reflection->getProperty('progress')->setValue($installer, null);
		$run = new ExternalInstallRun(new ExternalInstallRequest('notes', '1.1.0', [], new ExternalInstallOptions(false, false)), SourceBinding::appStore(), '1.0.0');
		$run->previousEnabled = 'no';
		$check = new ArchiveCheck('sha', true, null);

		$reflection->getMethod('keepBackup')->invoke($installer, new ExternalInstall($run, $check, new StagedRelease($this->root . '/staged', [], $this->root . '/notes', $this->root . '/notes'), null));
		$result = $reflection->getMethod('resultEnvelope')->invoke($installer, new ExternalInstall($run, $check, new StagedRelease($this->root . '/staged', [], $this->root . '/notes', $this->root . '/notes'), null), ['binding' => SourceBinding::appStore(), 'installedApp' => 'notes']);
		$this->assertSame($made, $result['backup']);

		$reflection->getMethod('keepBackup')->invoke($installer, new ExternalInstall($run, $check, new StagedRelease($this->root . '/staged', [], null, $this->root . '/fresh'), null));
		$result = $reflection->getMethod('resultEnvelope')->invoke($installer, new ExternalInstall($run, $check, new StagedRelease($this->root . '/staged', [], null, $this->root . '/fresh'), null), ['binding' => SourceBinding::appStore(), 'installedApp' => 'notes']);
		$this->assertArrayNotHasKey('backup', $result);
	}

	/**
	 * @param class-string $installerClass
	 */
	private function invokeRestore(string $installerClass, string $destination, ?string $backup): bool {
		return (bool)$this->invokePrivate($installerClass, 'restoreFromBackup', [$destination, $backup]);
	}

	/**
	 * @param class-string $installerClass
	 * @param array<int, mixed> $args
	 */
	private function invokePrivate(string $installerClass, string $method, array $args): mixed {
		$reflection = new ReflectionClass($installerClass);
		$instance = $reflection->newInstanceWithoutConstructor();
		$m = $reflection->getMethod($method);
		$m->setAccessible(true);

		return $m->invokeArgs($instance, $args);
	}

	private function removeRecursive(string $path): void {
		if (!is_dir($path)) {
			if (is_file($path)) {
				unlink($path);
			}

			return;
		}
		$entries = scandir($path) ?: [];
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$this->removeRecursive($path . '/' . $entry);
		}
		rmdir($path);
	}
}
