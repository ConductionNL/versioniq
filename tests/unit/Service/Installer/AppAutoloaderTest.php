<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\AppAutoloader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InstallerGoldenSupport.php';

/**
 * Nextcloud 35 removed `\OC_App::registerAutoloading()`. The finalizer must
 * still put a freshly extracted app's namespace on the autoloader before its
 * migrations run, so AppAutoloader carries a public-API/plain-PHP fallback.
 */
class AppAutoloaderTest extends TestCase {
	private string $appPath;

	/** @var list<string> Extra app folders a single test builds. */
	private array $extraDirs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->appPath = sys_get_temp_dir() . '/versioniq-al-' . bin2hex(random_bytes(4));
		mkdir($this->appPath . '/lib/Migration', 0777, true);
		file_put_contents(
			$this->appPath . '/lib/Migration/Probe.php',
			"<?php\nnamespace OCA\\VersioniqAlProbe\\Migration;\nfinal class Probe {}\n"
		);
	}

	protected function tearDown(): void {
		@unlink($this->appPath . '/lib/Migration/Probe.php');
		@rmdir($this->appPath . '/lib/Migration');
		@rmdir($this->appPath . '/lib');
		@rmdir($this->appPath);
		foreach ($this->extraDirs as $dir) {
			InstallerGoldenSupport::removeTree($dir);
		}
		$this->extraDirs = [];
		parent::tearDown();
	}

	/**
	 * An empty app folder of this test's own, removed again in tearDown().
	 */
	private function makeAppDir(string $prefix): string {
		$dir = sys_get_temp_dir() . '/versioniq-al-' . $prefix . '-' . bin2hex(random_bytes(4));
		mkdir($dir, 0777, true);
		$this->extraDirs[] = $dir;

		return $dir;
	}

	public function testFinalizerNoLongerCallsTheRemovedStaticDirectly(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Service/Installer/InstallFinalizer.php');
		$code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
		$this->assertStringNotContainsString('OC_App::registerAutoloading', $code);
	}

	public function testNamespaceComesFromTheAppManagerWhenItAnswers(): void {
		$appManager = $this->createMock(IAppManager::class);
		if (!method_exists(IAppManager::class, 'getAppNamespace')) {
			// Before Nextcloud 34 the interface has no getAppNamespace(), so
			// info.xml is the first source that answers.
			$this->assertSame('OCA\\Other', AppAutoloader::resolveNamespace('myapp', $appManager, ['namespace' => 'Other']));
			return;
		}
		$appManager->method('getAppNamespace')->with('myapp')->willReturn('OCA\\MyApp');

		$this->assertSame('OCA\\MyApp', AppAutoloader::resolveNamespace('myapp', $appManager, ['namespace' => 'Other']));
	}

	public function testNamespaceFallsBackToInfoXmlThenAppId(): void {
		$appManager = $this->createMock(IAppManager::class);
		if (method_exists(IAppManager::class, 'getAppNamespace')) {
			// Nextcloud 34+: the app manager is asked first; when it throws,
			// info.xml and then the app id answer instead.
			$appManager->method('getAppNamespace')->willThrowException(new \RuntimeException('boom'));
		}

		$this->assertSame('OCA\\OpenRegister', AppAutoloader::resolveNamespace('openregister', $appManager, ['namespace' => 'OpenRegister']));
		$this->assertSame('OCA\\Myapp', AppAutoloader::resolveNamespace('myapp', $appManager, []));
	}

	public function testClassFileOnlyAnswersForTheAppNamespace(): void {
		$this->assertSame('/x/lib/Db/Foo.php', AppAutoloader::classFile('OCA\\MyApp', '/x/', 'OCA\\MyApp\\Db\\Foo'));
		$this->assertNull(AppAutoloader::classFile('OCA\\MyApp', '/x', 'OCA\\MyAppOther\\Foo'));
		$this->assertNull(AppAutoloader::classFile('OCA\\MyApp', '/x', 'OCA\\MyApp\\'));
	}

	public function testFallbackMakesTheAppsClassesLoadable(): void {
		AppAutoloader::registerFallback('OCA\\VersioniqAlProbe', 'versioniqalprobe', $this->appPath . '/');
		AppAutoloader::registerFallback('OCA\\VersioniqAlProbe', 'versioniqalprobe', $this->appPath);

		$this->assertTrue(class_exists('OCA\\VersioniqAlProbe\\Migration\\Probe'));
	}

	public function testRegisterDelegatesToTheLegacyStaticWhereTheServerStillHasIt(): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('Records \OC_App calls through the unit-only bootstrap\'s stub.');
		}
		// Nextcloud 32-34 (and the unit-only bootstrap's stub) ship the static,
		// so it is the registrar register() must pick, untouched.
		$this->assertTrue(AppAutoloader::legacyRegistrarAvailable());
		$calls = [];
		$saved = \OC_App::$recorder;
		\OC_App::$recorder = static function (string $call, array $args) use (&$calls): void {
			$calls[] = [$call, $args];
		};

		try {
			AppAutoloader::register('myapp', '/apps/myapp', $this->createMock(IAppManager::class), ['namespace' => 'MyApp']);
		} finally {
			\OC_App::$recorder = $saved;
		}

		$this->assertSame([['OC_App::registerAutoloading', ['myapp', '/apps/myapp']]], $calls);
	}

	public function testTheFallbackPrefersTheAppsOwnComposerAutoloader(): void {
		$appPath = $this->makeAppDir('composer');
		mkdir($appPath . '/composer', 0777, true);
		file_put_contents(
			$appPath . '/composer/autoload.php',
			"<?php\nnamespace OCA\\VersioniqAlComposer;\nfinal class Marker {}\n"
		);
		mkdir($appPath . '/lib', 0777, true);
		$autoloadersBefore = count(spl_autoload_functions() ?: []);

		AppAutoloader::registerFallback('OCA\\VersioniqAlComposer', 'versioniqalcomposer', $appPath);

		// Required outright, not left to an autoloader of ours: the app ships
		// its own class map, so no PSR-4 loader is registered on top.
		$this->assertTrue(class_exists('OCA\\VersioniqAlComposer\\Marker', false));
		$this->assertCount($autoloadersBefore, spl_autoload_functions() ?: []);
	}

	public function testTheFallbackRegistersNothingForAnAppWithNeitherComposerNorLib(): void {
		$appPath = $this->makeAppDir('bare');
		$autoloadersBefore = count(spl_autoload_functions() ?: []);

		AppAutoloader::registerFallback('OCA\\VersioniqAlBare', 'versioniqalbare', $appPath);

		$this->assertCount($autoloadersBefore, spl_autoload_functions() ?: []);
		$this->assertFalse(class_exists('OCA\\VersioniqAlBare\\Thing'));
	}
}
