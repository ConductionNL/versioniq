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
 * @covers \OCA\Versioniq\Service\Installer\ReplacesAppFolder
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
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

	private function copier(): object {
		return new class {
			use ReplacesAppFolder;

			public function copy(string $source, string $destination): void {
				$this->copyRecursive($source, $destination);
			}

			private function addDebug(string $stage, mixed $data = null): void {
			}
		};
	}
}
