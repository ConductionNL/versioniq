<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class EnvironmentCheckTest extends TestCase {
	/** @var list<string> */
	private array $tempDirs = [];

	protected function tearDown(): void {
		foreach (array_reverse($this->tempDirs) as $dir) {
			@chmod($dir, 0777);
			@chmod(dirname($dir), 0777);
		}
		foreach (array_reverse($this->tempDirs) as $dir) {
			$this->removeRecursive($dir);
		}
		$this->tempDirs = [];
		parent::tearDown();
	}

	private function build(): EnvironmentCheck {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		return new EnvironmentCheck($factory);
	}

	/**
	 * Creates a parent/app folder pair under the system temp dir and returns the
	 * app path. The parent is what governs writability.
	 */
	private function makeAppDir(): string {
		$parent = sys_get_temp_dir() . '/av-envcheck-' . uniqid('', true);
		$appPath = $parent . '/sampleapp';
		mkdir($appPath, 0777, true);
		$this->tempDirs[] = $parent;

		return $appPath;
	}

	private function removeRecursive(string $dir): void {
		if (!is_dir($dir)) {
			@unlink($dir);

			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $item) {
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($dir);
	}

	public function testWritableParentReportsManageable(): void {
		$appPath = $this->makeAppDir();
		$result = $this->build()->inspect($appPath);

		self::assertTrue($result['manageable']);
	}

	public function testNonWritableParentReportsNotManageableWithWarning(): void {
		if (function_exists('posix_getuid') && posix_getuid() === 0) {
			self::markTestSkipped('Running as root bypasses directory permissions.');
		}

		$appPath = $this->makeAppDir();
		// Make the PARENT directory non-writable so rename() of the app folder
		// would fail — this is the authoritative functional check.
		chmod(dirname($appPath), 0555);

		$check = $this->build();
		self::assertFalse($check->isDestinationWritable($appPath));

		$result = $check->inspect($appPath);
		self::assertFalse($result['manageable']);
		self::assertNotNull($result['warning']);
		self::assertNotSame('', $result['warning']);
	}

	public function testGitCheckoutEnrichesWarningButDoesNotBlock(): void {
		$appPath = $this->makeAppDir();
		mkdir($appPath . '/.git', 0777, true);

		$result = $this->build()->inspect($appPath);

		// Writable + .git: advisory only — still manageable.
		self::assertTrue($result['manageable']);
		self::assertNotNull($result['warning']);
	}

	public function testWritablePlainAppHasNoWarning(): void {
		$appPath = $this->makeAppDir();
		$result = $this->build()->inspect($appPath);

		self::assertTrue($result['manageable']);
		// A plain writable folder owned by the test user yields no advisory.
		if ($result['warning'] !== null) {
			// Owner-mismatch can legitimately fire in some CI setups; only the
			// .git heuristic must be absent here.
			self::assertStringNotContainsString('Git', (string)$result['warning']);
		}
	}
	private const DEV_CHECKOUT_WARNING = 'This app looks like a development checkout (e.g. a Git working copy or a bind mount owned by another user). Version changes here will overwrite your working copy.';

	private const NOT_WRITABLE_WARNING = 'This app\'s folder is not writable by the web-server user, so version changes will fail.'
		. ' It is likely a bind-mounted dev checkout — fix the folder ownership/permissions to manage it here.';

	/**
	 * The authoritative check reads the PARENT directory, so a folder whose
	 * parent is not there at all is not writable either — and unlike a chmod,
	 * that holds for a process running as root, which bypasses permissions.
	 * The card then carries the blocking warning and no advisory.
	 */
	public function testAnAppFolderWithoutAParentDirectoryIsNotManageable(): void {
		$appPath = sys_get_temp_dir() . '/av-envcheck-gone-' . uniqid('', true) . '/sampleapp';
		$check = $this->build();

		self::assertFalse($check->isDestinationWritable($appPath));
		self::assertSame(['manageable' => false, 'warning' => self::NOT_WRITABLE_WARNING], $check->inspect($appPath));
	}

	public function testGitCheckoutWarningIsTheDevCheckoutText(): void {
		$appPath = $this->makeAppDir();
		mkdir($appPath . '/.git', 0777, true);

		self::assertSame(['manageable' => true, 'warning' => self::DEV_CHECKOUT_WARNING], $this->build()->inspect($appPath));
	}

	public function testFolderOwnedByAnotherUserEnrichesWarningButDoesNotBlock(): void {
		if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
			self::markTestSkipped('Changing a folder\'s owner needs root.');
		}
		$appPath = $this->makeAppDir();
		chown($appPath, 65534);
		clearstatcache();

		self::assertSame(['manageable' => true, 'warning' => self::DEV_CHECKOUT_WARNING], $this->build()->inspect($appPath));
	}

	public function testGitCheckoutOwnedByAnotherUserStillWarnsOnce(): void {
		if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
			self::markTestSkipped('Changing a folder\'s owner needs root.');
		}
		$appPath = $this->makeAppDir();
		mkdir($appPath . '/.git', 0777, true);
		chown($appPath, 65534);
		clearstatcache();

		self::assertSame(['manageable' => true, 'warning' => self::DEV_CHECKOUT_WARNING], $this->build()->inspect($appPath));
	}

	public function testPlainFolderOwnedByThisUserHasNoWarning(): void {
		if (!function_exists('posix_getuid')) {
			self::markTestSkipped('posix is needed to know the process owner.');
		}
		$appPath = $this->makeAppDir();
		if (fileowner($appPath) !== posix_getuid()) {
			self::markTestSkipped('The temp folder is not owned by the test user.');
		}

		self::assertSame(['manageable' => true, 'warning' => null], $this->build()->inspect($appPath));
	}

	/**
	 * A check whose folders have the given free bytes (null: unknown), with
	 * the temp folder at /tmp-vq. Records which folders it asked about.
	 *
	 * @param array<string, int|null> $free Free bytes per folder.
	 * @param list<string>            $asked Collects the folders asked.
	 */
	private function withFreeSpace(array $free, array &$asked = []): EnvironmentCheck {
		$factory = $this->createMock(IFactory::class);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTempBaseDir')->willReturn('/tmp-vq');

		return new EnvironmentCheck($factory, $temp, static function (string $dir) use ($free, &$asked): float|false {
			$asked[] = $dir;
			$bytes = $free[$dir] ?? null;

			return $bytes === null ? false : (float)$bytes;
		});
	}

	/**
	 * safety-rollback-backup-and-preflight D2: three times the package in the
	 * apps folder, two times in temp, the boundary included.
	 */
	public function testSpaceIsThreeTimesThePackageNextToTheAppAndTwiceInTemp(): void {
		$asked = [];
		$fits = $this->withFreeSpace(['/apps-extra' => 300, '/tmp-vq' => 200], $asked)->hasSpaceFor('/apps-extra/procest', 100);
		self::assertSame(['fits' => true, 'appsFree' => 300, 'appsNeeded' => 300, 'tempFree' => 200, 'tempNeeded' => 200], $fits);
		self::assertSame(['/apps-extra', '/tmp-vq'], $asked);

		self::assertFalse($this->withFreeSpace(['/apps-extra' => 299, '/tmp-vq' => 200])->hasSpaceFor('/apps-extra/procest', 100)['fits']);
		self::assertFalse($this->withFreeSpace(['/apps-extra' => 300, '/tmp-vq' => 199])->hasSpaceFor('/apps-extra/procest', 100)['fits']);
	}

	public function testAnUnknownPackageSizeAssumesFiftyMegabytes(): void {
		$space = $this->withFreeSpace(['/apps-extra' => 1, '/tmp-vq' => 1])->hasSpaceFor('/apps-extra/procest', null);
		self::assertSame(3 * EnvironmentCheck::DEFAULT_PACKAGE_BYTES, $space['appsNeeded']);
		self::assertSame(2 * EnvironmentCheck::DEFAULT_PACKAGE_BYTES, $space['tempNeeded']);
		self::assertSame(52428800, EnvironmentCheck::DEFAULT_PACKAGE_BYTES);
		self::assertSame(3 * EnvironmentCheck::DEFAULT_PACKAGE_BYTES, $this->withFreeSpace([])->hasSpaceFor('/a/b', 0)['appsNeeded']);
	}

	public function testUnknownFreeSpaceDoesNotBlockAndAFreshInstallChecksTempOnly(): void {
		self::assertTrue($this->withFreeSpace([])->hasSpaceFor('/apps-extra/procest', 100)['fits']);

		$asked = [];
		$fresh = $this->withFreeSpace(['/tmp-vq' => 150], $asked)->hasSpaceFor(null, 100);
		self::assertFalse($fresh['fits']);
		self::assertNull($fresh['appsFree']);
		self::assertSame(['/tmp-vq'], $asked);
	}
}
