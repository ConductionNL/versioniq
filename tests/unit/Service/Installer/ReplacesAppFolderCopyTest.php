<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

require_once __DIR__ . '/InstallerGoldenSupport.php';

use Exception;
use OC\Files\FilenameValidator;
use OCA\Versioniq\Service\Installer\ReplacesAppFolder;
use PHPUnit\Framework\TestCase;

/**
 * The recursive copy of an extracted app: what lands in the destination,
 * which files the filename validator is asked about, and the refusals.
 *
 * Also the primitives around the copy, against the same real temp tree: moving
 * the live folder aside, finding the extracted root, restoring a backup after
 * a failed swap and deleting what is left over.
 *
 * @covers \OCA\Versioniq\Service\Installer\ReplacesAppFolder
 */
final class ReplacesAppFolderCopyTest extends TestCase {
	private string $root;

	/** @var list<string> */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('Needs the unit-only bootstrap.');
		}
		$this->root = sys_get_temp_dir() . '/vq-copy-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/src/lib/Sub', 0777, true);
		file_put_contents($this->root . '/src/info.txt', 'info');
		file_put_contents($this->root . '/src/forbidden.txt', 'no');
		file_put_contents($this->root . '/src/lib/Sub/Plugin.php', '<?php');
		$validator = $this->createMock(FilenameValidator::class);
		$validator->method('isForbidden')->willReturnCallback(function (string $path): bool {
			$this->asked[] = substr($path, strlen($this->root));

			return str_ends_with($path, 'forbidden.txt');
		});
		InstallerGoldenSupport::useServices([FilenameValidator::class => $validator]);
	}

	protected function tearDown(): void {
		InstallerGoldenSupport::resetServices();
		if (isset($this->root) && is_dir($this->root)) {
			exec('rm -rf ' . escapeshellarg($this->root));
		}
		parent::tearDown();
	}

	public function testCopiesTheTreeAndSkipsForbiddenFiles(): void {
		$this->copier()->copy($this->root . '/src', $this->root . '/dest');

		$this->assertSame(['/src/forbidden.txt', '/src/info.txt', '/src/lib/Sub/Plugin.php'], $this->asked);
		$this->assertSame(['dest/info.txt', 'dest/lib/Sub/Plugin.php'], $this->filesUnder('dest'));
		$this->assertSame('<?php', file_get_contents($this->root . '/dest/lib/Sub/Plugin.php'));
	}

	public function testRefusesASourceThatIsNoFolder(): void {
		$this->expectExceptionObject(new Exception('Invalid extracted app source folder.'));

		$this->copier()->copy($this->root . '/src/info.txt', $this->root . '/dest');
	}

	public function testRefusesADestinationThatCannotBeCreated(): void {
		$this->expectExceptionObject(new Exception('Could not create destination folder.'));

		@$this->copier()->copy($this->root . '/src', $this->root . '/src/info.txt/dest');
	}

	public function testRefusesAFileThatCannotBeCopied(): void {
		mkdir($this->root . '/dest/info.txt', 0777, true);
		$this->expectExceptionObject(new Exception('Could not copy app file "info.txt".'));

		@$this->copier()->copy($this->root . '/src', $this->root . '/dest');
	}

	/**
	 * @return list<string>
	 */
	private function filesUnder(string $dir): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			$files[] = substr((string)$file, strlen($this->root) + 1);
		}
		sort($files);

		return $files;
	}

	public function testBackupMovesTheLiveFolderAsideOverAStaleBackup(): void {
		mkdir($this->root . '/app', 0777, true);
		file_put_contents($this->root . '/app/marker.txt', 'LIVE');
		mkdir($this->root . '/app.appversion-backup/old', 0777, true);

		$backup = $this->copier()->backup($this->root . '/app');

		$this->assertSame($this->root . '/app.appversion-backup', $backup);
		$this->assertDirectoryDoesNotExist($this->root . '/app');
		$this->assertDirectoryDoesNotExist($this->root . '/app.appversion-backup/old', 'the stale backup is replaced, not merged');
		$this->assertSame('LIVE', file_get_contents($this->root . '/app.appversion-backup/marker.txt'));
	}

	public function testBackupRefusesWhenTheLiveFolderCannotBeMovedAside(): void {
		mkdir($this->root . '/app', 0777, true);
		file_put_contents($this->root . '/app/marker.txt', 'LIVE');
		// A plain file where the backup folder goes: rename() cannot replace it.
		file_put_contents($this->root . '/app.appversion-backup', 'in the way');

		$refusal = null;
		try {
			@$this->copier()->backup($this->root . '/app');
		} catch (Exception $e) {
			$refusal = $e->getMessage();
		}

		$this->assertSame('Could not backup existing app folder before replacement.', $refusal);
		$this->assertSame('LIVE', file_get_contents($this->root . '/app/marker.txt'), 'the live app must be left alone');
	}

	public function testRestoreKeepsTheBackupWhenTheDestinationPathIsUnusable(): void {
		mkdir($this->root . '/app.appversion-backup', 0777, true);
		file_put_contents($this->root . '/app.appversion-backup/marker.txt', 'OLD');

		// A path PHP refuses outright (a NUL byte): the ValueError must not
		// escape the restore, the caller only learns it did not happen.
		$restored = $this->copier()->restore($this->root . "/app\0x", $this->root . '/app.appversion-backup');

		$this->assertFalse($restored);
		$this->assertSame('OLD', file_get_contents($this->root . '/app.appversion-backup/marker.txt'), 'the backup must survive');
	}

	public function testTheExtractedRootIsTheOneTopLevelFolder(): void {
		$this->assertSame($this->root . '/src/lib', $this->copier()->single($this->root . '/src'));
	}

	public function testThereIsNoExtractedRootWhenThePathCannotBeListed(): void {
		$this->assertNull(@$this->copier()->single($this->root . '/src/info.txt'));
	}

	public function testRemovingALeftoverDeletesAPlainFile(): void {
		file_put_contents($this->root . '/leftover.tmp', 'x');

		$this->copier()->remove($this->root . '/leftover.tmp');

		$this->assertFileDoesNotExist($this->root . '/leftover.tmp');
	}

	public function testRemovingALeftoverDeletesADanglingSymlink(): void {
		symlink($this->root . '/gone', $this->root . '/dangling');
		$this->assertTrue(is_link($this->root . '/dangling'));

		$this->copier()->remove($this->root . '/dangling');

		$this->assertFalse(is_link($this->root . '/dangling'));
	}

	public function testRemovingALeftoverThatIsNotThereDoesNothing(): void {
		$this->copier()->remove($this->root . '/never-existed');

		$this->assertFileDoesNotExist($this->root . '/never-existed');
		$this->assertDirectoryExists($this->root, 'the parent must be left alone');
	}

	private function copier(): object {
		return new class {
			use ReplacesAppFolder;

			public function copy(string $source, string $destination): void {
				$this->copyRecursive($source, $destination);
			}

			public function backup(string $destination): ?string {
				return $this->backupExisting($destination);
			}

			public function restore(string $destination, ?string $backup): bool {
				return $this->restoreFromBackup($destination, $backup);
			}

			public function single(string $path): ?string {
				return $this->findSingleDirectory($path);
			}

			public function remove(string $path): void {
				$this->rmdirr($path);
			}

			private function addDebug(string $stage, mixed $data = null): void {
			}
		};
	}
}
