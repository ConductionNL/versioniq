<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\MigrationDiffer;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the migration diff algorithm against real fixture directories —
 * mirrors {@see \OCA\Versioniq\Tests\Unit\Service\InstallerRecoveryTest}'s
 * approach of driving filesystem primitives against a real temp tree, since
 * the diff itself is a pure filesystem read with no network dependency.
 */
final class MigrationDifferTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/appversions-migrationdiff-' . uniqid('', true);
		mkdir($this->root, 0777, true);
	}

	protected function tearDown(): void {
		$this->removeRecursive($this->root);
		parent::tearDown();
	}

	private function makeMigrationDir(string $appPath, array $files): void {
		$dir = $appPath . '/lib/Migration';
		mkdir($dir, 0777, true);
		foreach ($files as $file) {
			file_put_contents($dir . '/' . $file, '<?php // fixture');
		}
	}

	public function testDiffNamesStepsPresentInInstalledButAbsentFromTarget(): void {
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		$this->makeMigrationDir($installed, [
			'Version1000Date20260101000000.php',
			'Version2040Date20260201000000.php',
		]);
		$this->makeMigrationDir($target, [
			'Version1000Date20260101000000.php',
		]);

		$diff = (new MigrationDiffer())->diff($installed, $target);

		self::assertSame(['Version2040Date20260201000000'], $diff);
	}

	public function testDiffIsEmptyWhenMigrationSetsAreIdentical(): void {
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		$this->makeMigrationDir($installed, ['Version1000Date20260101000000.php']);
		$this->makeMigrationDir($target, ['Version1000Date20260101000000.php']);

		$diff = (new MigrationDiffer())->diff($installed, $target);

		self::assertSame([], $diff);
	}

	public function testDiffIsEmptyWhenNeitherSideHasAMigrationsDirectory(): void {
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		mkdir($installed, 0777, true);
		mkdir($target, 0777, true);

		$diff = (new MigrationDiffer())->diff($installed, $target);

		self::assertSame([], $diff);
	}

	public function testDiffIsSortedRegardlessOfFilesystemOrder(): void {
		$installed = $this->root . '/installed';
		$target = $this->root . '/target';
		$this->makeMigrationDir($installed, [
			'Version3000Date20260301000000.php',
			'Version1000Date20260101000000.php',
			'Version2000Date20260201000000.php',
		]);
		mkdir($target, 0777, true);

		$diff = (new MigrationDiffer())->diff($installed, $target);

		self::assertSame([
			'Version1000Date20260101000000',
			'Version2000Date20260201000000',
			'Version3000Date20260301000000',
		], $diff);
	}

	public function testDiffReturnsNullWhenTheInstalledPathIsUnknown(): void {
		$target = $this->root . '/target';
		mkdir($target, 0777, true);

		$diff = (new MigrationDiffer())->diff(null, $target);

		self::assertNull($diff);
	}

	public function testDiffReturnsNullWhenTheTargetPathIsUnknown(): void {
		$installed = $this->root . '/installed';
		mkdir($installed, 0777, true);

		$diff = (new MigrationDiffer())->diff($installed, null);

		self::assertNull($diff);
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
