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
use OC\AppFramework\Bootstrap\Coordinator;
use OCA\Versioniq\Service\Installer\ChildProcessFinalizer;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\ServerAccess;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Settings\ConfigStores;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IBinaryFinder;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/InstallerGoldenSupport.php';

/**
 * When an install's finalize phase runs in a child process (#611): only for an
 * update, never inside the child itself, and never without a way to start one;
 * how the parent reads the child's outcome; and how it takes over the system
 * config values the child wrote, so its own next config write keeps them.
 *
 * The run() tests replace the child's command with a plain `php -r` script, so
 * no Nextcloud is booted; main()'s success path needs one and is not covered here.
 *
 * @covers \OCA\Versioniq\Service\Installer\ChildProcessFinalizer
 * @covers \OCA\Versioniq\Service\Installer\InstallFinalizer::finalize
 * @uses \OCA\Versioniq\Service\Installer\ServerAccess
 * @uses \OCA\Versioniq\Service\Settings\ConfigStores
 */
final class ChildProcessFinalizerTest extends TestCase {
	private const RESULT_PREFIX = 'VERSIONIQ_FINALIZE_RESULT ';

	private IAppConfig&MockObject $appConfig;

	private IConfig&MockObject $config;

	private IAppManager&MockObject $appManager;

	private LoggerInterface&MockObject $logger;

	/** @var list<string> Every stderr file the finalizer was handed. */
	private array $stderrFiles = [];

	private string $configDir = '';

	private ?string $savedConfigDir = null;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		putenv(ChildProcessFinalizer::CHILD_ENV);

		// The config sync reads \OC::$configDir . 'config.php'; point it at
		// a directory of this test's own, empty until a test writes one.
		$this->savedConfigDir = \OC::$configDir;
		$this->configDir = sys_get_temp_dir() . '/vq-config-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->configDir);
		\OC::$configDir = $this->configDir;
	}

	protected function tearDown(): void {
		\OC::$configDir = $this->savedConfigDir;
		foreach ((array)glob($this->configDir . '*') as $file) {
			unlink((string)$file);
		}
		rmdir($this->configDir);
		foreach ($this->stderrFiles as $file) {
			@unlink($file);
		}
		putenv(ChildProcessFinalizer::CHILD_ENV);
		parent::tearDown();
	}

	private function finalizer(string $installedVersion): ChildProcessFinalizer {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'installed_version' ? $installedVersion : $default
		);

		return new ChildProcessFinalizer(
			$this->createMock(IBinaryFinder::class),
			$this->serverAccess(),
			$this->logger,
			$this->tempManager(),
		);
	}

	/**
	 * A finalizer whose child runs the given PHP code instead of booting Nextcloud.
	 */
	private function finalizerWithChild(string $childCode): ChildProcessFinalizer {
		return new class($childCode, $this->createMock(IBinaryFinder::class), $this->serverAccess(), $this->logger, $this->tempManager()) extends ChildProcessFinalizer {
			public function __construct(
				private string $childCode,
				IBinaryFinder $binaryFinder,
				ServerAccess $server,
				LoggerInterface $logger,
				ITempManager $tempManager,
			) {
				parent::__construct($binaryFinder, $server, $logger, $tempManager);
			}

			protected function childCommand(string $php, array $childArgs): array {
				return [$php, '-r', $this->childCode];
			}
		};
	}

	private function serverAccess(): ServerAccess {
		return new ServerAccess($this->appManager, new ConfigStores($this->config, $this->appConfig), $this->createMock(IEventDispatcher::class));
	}

	private function tempManager(): ITempManager {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$file = (string)tempnam(sys_get_temp_dir(), 'vq-test-');
			$this->stderrFiles[] = $file;

			return $file;
		});

		return $tempManager;
	}

	/**
	 * PHP code that prints the child's result line.
	 *
	 * @param array<string, mixed> $result
	 */
	private static function printsResult(array $result): string {
		return 'echo ' . var_export(self::RESULT_PREFIX . json_encode($result) . "\n", true) . ';';
	}

	/**
	 * PHP code that overwrites config.php with the given config, the way a
	 * child that calls IConfig::setSystemValue() does.
	 *
	 * @param array<string, mixed> $config
	 */
	private function writesConfig(array $config): string {
		$next = $this->configDir . 'next.php';
		self::writeConfigFile($next, $config);

		return 'copy(' . var_export($next, true) . ', ' . var_export($this->configDir . 'config.php', true) . ');';
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private static function writeConfigFile(string $file, array $config): void {
		file_put_contents($file, "<?php\n\$CONFIG = " . var_export($config, true) . ";\n");
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function baseConfig(): array {
		return [
			'version' => '32.0.0.1',
			'unchanged' => ['a' => 1, 'b' => [2, 3]],
			'changed' => ['host-a.example'],
			'typed' => 2,
			'removed' => 'gone',
		];
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

	public function testTheChildMarksItsOwnProcessSoItNeverStartsAnother(): void {
		$this->expectOutputRegex('/^VERSIONIQ_FINALIZE_RESULT /');

		ChildProcessFinalizer::main([]);

		$this->assertSame('1', getenv(ChildProcessFinalizer::CHILD_ENV));
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

	public function testASuccessfulChildReturnsTheAppIdAndClearsTheCaches(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$this->appConfig->expects($this->once())->method('clearCache')->with(true);
		$this->appManager->expects($this->once())->method('clearAppsCache');
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->never())->method('warning');

		$appId = $this->finalizerWithChild(self::printsResult(['ok' => true, 'appId' => 'openregister']))
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertSame('openregister', $appId);
		$this->assertCount(1, $this->stderrFiles);
		$this->assertFileDoesNotExist($this->stderrFiles[0]);
	}

	public function testOutputBeforeTheResultLineDoesNotBreakParsing(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$noise = 'echo "PHP Deprecated: something in lib/base.php\nnot a VERSIONIQ_FINALIZE_RESULT line\n";';
		$staleFailure = self::printsResult(['ok' => false, 'message' => 'an earlier line']);

		$appId = $this->finalizerWithChild($noise . $staleFailure . self::printsResult(['ok' => true, 'appId' => 'openregister']))
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertSame('openregister', $appId);
	}

	public function testAChildThatReportsAFailureFailsWithItsMessage(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$this->logger->expects($this->once())->method('error');
		$this->appConfig->expects($this->never())->method('clearCache');
		$this->appManager->expects($this->never())->method('clearAppsCache');
		$child = self::printsResult(['ok' => false, 'class' => RuntimeException::class, 'message' => 'Repair step OCA\\OpenRegister\\Repair\\X failed: no service']) . 'exit(1);';

		try {
			$this->finalizerWithChild($child)->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');
			$this->fail('run() must throw when the child reports a failure.');
		} catch (Exception $e) {
			$this->assertSame('Repair step OCA\\OpenRegister\\Repair\\X failed: no service', $e->getMessage());
		}
		$this->assertFileDoesNotExist($this->stderrFiles[0]);
	}

	public function testAChildThatDiesWithoutAResultLineReportsItsStderr(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$this->logger->expects($this->once())->method('error');
		$child = 'echo "some output\n"; fwrite(STDERR, "PHP Fatal error: Uncaught Error: boom in /apps/x.php:12\n"); exit(255);';

		try {
			$this->finalizerWithChild($child)->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');
			$this->fail('run() must throw when the child dies.');
		} catch (Exception $e) {
			$this->assertStringContainsString('failed in the child process (exit 255)', $e->getMessage());
			$this->assertStringContainsString('PHP Fatal error: Uncaught Error: boom in /apps/x.php:12', $e->getMessage());
			$this->assertStringNotContainsString('some output', $e->getMessage());
		}
	}

	public function testTheConfigKeysTheChildChangedAreAppliedToThisProcess(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$after = self::baseConfig();
		unset($after['removed']);
		$after['changed'] = ['host-a.example', 'host-b.example'];
		$after['typed'] = '2';
		$after['added'] = 'secret-value';
		$this->config->expects($this->once())->method('setSystemValues')->with($this->callback(function (array $changes): bool {
			ksort($changes);
			$this->assertSame([
				'added' => 'secret-value',
				'changed' => ['host-a.example', 'host-b.example'],
				'removed' => null,
				'typed' => '2',
			], $changes);

			return true;
		}));
		$logged = [];
		$this->logger->expects($this->once())->method('info')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
			$logged = [$message, $context];
		});

		$this->finalizerWithChild($this->writesConfig($after) . self::printsResult(['ok' => true, 'appId' => 'openregister']))
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertSame('changed, typed, added, removed', $logged[1]['keys'] ?? null);
		$this->assertStringNotContainsString('secret-value', (string)json_encode($logged));
	}

	public function testAChildThatChangesNoConfigWritesNone(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$this->config->expects($this->never())->method('setSystemValues');
		$this->logger->expects($this->never())->method('info');

		$this->finalizerWithChild($this->writesConfig(self::baseConfig()) . self::printsResult(['ok' => true, 'appId' => 'openregister']))
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');
	}

	public function testAnUnreadableConfigFileSkipsTheSyncWithAWarning(): void {
		// No config.php in the config dir.
		$this->config->expects($this->never())->method('setSystemValues');
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not read config/config.php'));

		$appId = $this->finalizerWithChild(self::printsResult(['ok' => true, 'appId' => 'openregister']))
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertSame('openregister', $appId);
	}

	public function testTheConfigIsAlsoSyncedWhenTheChildFails(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$after = self::baseConfig();
		$after['added'] = true;
		$this->config->expects($this->once())->method('setSystemValues')->with(['added' => true]);
		$child = $this->writesConfig($after) . self::printsResult(['ok' => false, 'message' => 'Migration failed']) . 'exit(1);';

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Migration failed');

		$this->finalizerWithChild($child)->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');
	}

	public function testAConfigWriteThatFailsDoesNotFailTheFinalize(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$after = self::baseConfig();
		$after['added'] = true;
		$this->config->method('setSystemValues')->willThrowException(new RuntimeException('config is read-only'));
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not apply the system config keys'));

		$appId = $this->finalizerWithChild($this->writesConfig($after) . self::printsResult(['ok' => true, 'appId' => 'openregister']))
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertSame('openregister', $appId);
	}

	public function testFinalizeHandsAnUpdateToTheChild(): void {
		$child = $this->createMock(ChildProcessFinalizer::class);
		$child->method('applies')->willReturn(true);
		$child->expects($this->once())->method('run')
			->with('/apps/openregister', ['id' => 'openregister'], 'yes', 'github:o/r')
			->willReturn('openregister');

		$this->assertSame('openregister', $this->installFinalizer($child)->finalize('/apps/openregister', ['id' => 'openregister'], 'yes', null, 'github:o/r'));
	}

	public function testFinalizeStaysInProcessWhenNoChildCouldBeStarted(): void {
		$child = $this->createMock(ChildProcessFinalizer::class);
		$child->method('applies')->willReturn(true);
		$child->expects($this->once())->method('run')->willReturn(null);

		$this->assertFinalizesInProcess($this->installFinalizer($child));
	}

	public function testFinalizeStaysInProcessForAFreshInstall(): void {
		$child = $this->createMock(ChildProcessFinalizer::class);
		$child->method('applies')->willReturn(false);
		$child->expects($this->never())->method('run');

		$this->assertFinalizesInProcess($this->installFinalizer($child));
	}

	private function installFinalizer(ChildProcessFinalizer $child): InstallFinalizer {
		return new InstallFinalizer(
			new ConfigStores($this->config, $this->appConfig),
			$this->appManager,
			$this->createMock(IJobList::class),
			$this->logger,
			$this->createMock(LkgStore::class),
			$this->createMock(ITimeFactory::class),
			$child,
		);
	}

	/**
	 * The in-process finalize starts with the lazy registration; a server
	 * container without services makes that its observable first step.
	 */
	private function assertFinalizesInProcess(InstallFinalizer $installFinalizer): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('Replaces the server container, which only the unit-only bootstrap allows.');
		}

		$savedServer = \OC::$server;
		InstallerGoldenSupport::useServices([]);
		try {
			$installFinalizer->finalize('/apps/openregister', ['id' => 'openregister'], 'yes', null, 'github:o/r');
			$this->fail('finalize() must run in-process.');
		} catch (RuntimeException $e) {
			$this->assertSame('No test service for ' . Coordinator::class, $e->getMessage());
		} finally {
			\OC::$server = $savedServer;
		}
	}
}
