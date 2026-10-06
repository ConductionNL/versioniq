<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\AppAutoloader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud 35 removed `\OC_App::registerAutoloading()`. The finalizer must
 * still put a freshly extracted app's namespace on the autoloader before its
 * migrations run, so AppAutoloader carries a public-API/plain-PHP fallback.
 */
class AppAutoloaderTest extends TestCase {
	private string $appPath;

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
		parent::tearDown();
	}

	public function testFinalizerNoLongerCallsTheRemovedStaticDirectly(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Service/Installer/InstallFinalizer.php');
		$code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
		$this->assertStringNotContainsString('OC_App::registerAutoloading', $code);
	}

	public function testNamespaceComesFromTheAppManagerWhenItAnswers(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppNamespace')->with('myapp')->willReturn('OCA\\MyApp');

		$this->assertSame('OCA\\MyApp', AppAutoloader::resolveNamespace('myapp', $appManager, ['namespace' => 'Other']));
	}

	public function testNamespaceFallsBackToInfoXmlThenAppId(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppNamespace')->willThrowException(new \RuntimeException('boom'));

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
}
