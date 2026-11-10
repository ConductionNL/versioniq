<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use Exception;
use OCA\Versioniq\Service\Installer\ChildProcessFinalizer;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IBinaryFinder;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * When an install's finalize phase runs in a child process (#611): only for an
 * update, never inside the child itself, and never without a way to start one.
 *
 * @covers \OCA\Versioniq\Service\Installer\ChildProcessFinalizer
 * @covers \OCA\Versioniq\Service\Installer\InstallFinalizer::finalize
 */
final class ChildProcessFinalizerTest extends TestCase {
	private IAppConfig $appConfig;

	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		putenv(ChildProcessFinalizer::CHILD_ENV);
	}

	protected function tearDown(): void {
		putenv(ChildProcessFinalizer::CHILD_ENV);
		parent::tearDown();
	}

	private function finalizer(string $installedVersion): ChildProcessFinalizer {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(static fn (): string => (string)tempnam(sys_get_temp_dir(), 'vq-test-'));
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'installed_version' ? $installedVersion : $default
		);

		return new ChildProcessFinalizer(
			$this->createMock(IBinaryFinder::class),
			$this->appConfig,
			$this->createMock(IAppManager::class),
			$this->logger,
			$tempManager,
		);
	}

	public function testAnUpdateRunsInAChildProcess(): void {
		// Under phpunit PHP_SAPI is cli, so PHP_BINARY is the child's binary.
		$this->assertTrue($this->finalizer('2.1.41')->applies(['id' => 'openregister']));
	}

	public function testAFreshInstallStaysInProcess(): void {
		$this->assertFalse($this->finalizer('')->applies(['id' => 'openregister']));
	}

	public function testTheChildItselfFinalizesInProcess(): void {
		putenv(ChildProcessFinalizer::CHILD_ENV . '=1');

		$this->assertFalse($this->finalizer('2.1.41')->applies(['id' => 'openregister']));
	}

	public function testAnInfoWithoutAnAppIdStaysInProcess(): void {
		$this->assertFalse($this->finalizer('2.1.41')->applies([]));
	}

	public function testTheChildRejectsBadArgumentsWithAResultLine(): void {
		$this->expectOutputRegex('/^VERSIONIQ_FINALIZE_RESULT \{"ok":false,/');

		$this->assertSame(2, ChildProcessFinalizer::main(['openregister', '/does/not/exist', 'yes', 'appstore']));
	}

	public function testAChildThatCannotBootFailsTheFinalize(): void {
		// No Nextcloud under this root: the child dies on `require lib/base.php`.
		$savedRoot = \OC::$SERVERROOT;
		\OC::$SERVERROOT = sys_get_temp_dir() . '/vq-no-server-' . bin2hex(random_bytes(4));
		mkdir(\OC::$SERVERROOT);
		$this->logger->expects($this->once())->method('error');

		try {
			$this->finalizer('2.1.41')->run('/tmp/app', ['id' => 'openregister'], 'yes', 'appstore');
			$this->fail('run() must throw when the child fails.');
		} catch (Exception $e) {
			$this->assertStringContainsString('failed in the child process', $e->getMessage());
		} finally {
			rmdir(\OC::$SERVERROOT);
			\OC::$SERVERROOT = $savedRoot;
		}
	}

	public function testFinalizeHandsAnUpdateToTheChild(): void {
		$child = $this->createMock(ChildProcessFinalizer::class);
		$child->method('applies')->willReturn(true);
		$child->expects($this->once())->method('run')
			->with('/apps/openregister', ['id' => 'openregister'], 'yes', 'github:o/r')
			->willReturn('openregister');
		$installFinalizer = new InstallFinalizer(
			$this->appConfig,
			$this->createMock(IConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IJobList::class),
			$this->logger,
			$this->createMock(LkgStore::class),
			$this->createMock(ITimeFactory::class),
			$child,
		);

		$this->assertSame('openregister', $installFinalizer->finalize('/apps/openregister', ['id' => 'openregister'], 'yes', null, 'github:o/r'));
	}
}
