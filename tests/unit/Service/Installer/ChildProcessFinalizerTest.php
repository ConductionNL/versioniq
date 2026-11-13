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
 * no Nextcloud is booted; one test runs the real command against a fake server
 * root whose `lib/base.php` loads the unit-only bootstrap. main() is called
 * directly, with stubbed services behind `OCP\Server::get()`.
 *
 * @covers \OCA\Versioniq\Service\Installer\ChildProcessFinalizer
 * @covers \OCA\Versioniq\Service\Installer\InstallFinalizer::finalize
 * @uses \OCA\Versioniq\Service\Installer\ServerAccess
 * @uses \OCA\Versioniq\Service\Settings\ConfigStores
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 * @uses \OCA\Versioniq\Service\Installer\InstallFinalizer
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
		InstallerGoldenSupport::removeTree(rtrim($this->configDir, '/'));
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
	 * A finalizer whose child runs the given PHP code instead of booting
	 * Nextcloud; the probe child runs `$probeCode`, by default a probe that
	 * succeeds, so the real run is reached.
	 */
	private function finalizerWithChild(string $childCode, ?string $probeCode = null): ChildProcessFinalizer {
		$finalizer = new class($this->createMock(IBinaryFinder::class), $this->serverAccess(), $this->logger, $this->tempManager()) extends ChildProcessFinalizer {
			public string $childCode = '';

			public string $probeCode = '';

			protected function childCommand(string $php, array $childArgs): array {
				return [$php, '-r', $childArgs === [self::PROBE_ARG] ? $this->probeCode : $this->childCode];
			}
		};
		$finalizer->childCode = $childCode;
		$finalizer->probeCode = $probeCode ?? self::printsResult(['ok' => true, 'probe' => true]);

		return $finalizer;
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

	public function testAChildThatCannotBootFallsBackInProcessWithAWarning(): void {
		// No Nextcloud under this root: the probe dies on `require lib/base.php`.
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$savedRoot = \OC::$SERVERROOT;
		\OC::$SERVERROOT = sys_get_temp_dir() . '/vq-no-server-' . bin2hex(random_bytes(4));
		mkdir(\OC::$SERVERROOT);
		$this->logger->expects($this->never())->method('error');
		$warnings = [];
		$this->logger->expects($this->once())->method('warning')->willReturnCallback(function (string $message, array $context) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});
		$this->appConfig->expects($this->never())->method('clearCache');

		try {
			$appId = $this->finalizer('2.1.41')->run('/tmp/app', ['id' => 'openregister'], 'yes', 'appstore');
		} finally {
			rmdir(\OC::$SERVERROOT);
			\OC::$SERVERROOT = $savedRoot;
		}

		$this->assertNull($appId);
		$this->assertStringContainsString('could not boot Nextcloud (exit {exitCode}): {output}', $warnings[0][0]);
		$this->assertSame('openregister', $warnings[0][1]['app']);
		$this->assertSame(255, $warnings[0][1]['exitCode']);
		$this->assertStringContainsString('lib/base.php', $warnings[0][1]['output']);
		$this->assertCount(1, $this->stderrFiles, 'only the probe ran');
		$this->assertFileDoesNotExist($this->stderrFiles[0]);
	}

	public function testAProbeThatReportsAFailureFallsBackInProcess(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('could not boot Nextcloud'),
			$this->callback(static fn (array $context): bool => $context['output'] === 'RuntimeException: No service'),
		);
		$this->appConfig->expects($this->never())->method('clearCache');
		// A deprecation notice on stderr must not hide the result line's message.
		$probe = 'fwrite(STDERR, "PHP Deprecated: something in lib/base.php\n");'
			. self::printsResult(['ok' => false, 'class' => RuntimeException::class, 'message' => 'No service']);

		$appId = $this->finalizerWithChild(self::printsResult(['ok' => true, 'appId' => 'openregister']), $probe)
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertNull($appId);
		$this->assertCount(1, $this->stderrFiles, 'the real child must not run after a failed probe');
		$this->assertFileDoesNotExist($this->stderrFiles[0]);
	}

	public function testAProbeThatPrintsOkButExitsNonZeroFallsBackInProcess(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('could not boot Nextcloud'),
			$this->callback(static fn (array $context): bool => $context['exitCode'] === 255),
		);
		$this->appConfig->expects($this->never())->method('clearCache');
		$probe = self::printsResult(['ok' => true, 'probe' => true]) . 'exit(255);';

		$appId = $this->finalizerWithChild(self::printsResult(['ok' => true, 'appId' => 'openregister']), $probe)
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertNull($appId);
		$this->assertCount(1, $this->stderrFiles, 'the real child must not run after a failed probe');
	}

	public function testTheProbeAndTheRealChildGetTheirArgumentsInOrder(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$argsFile = $this->configDir . 'args.jsonl';
		$finalizer = new class($this->createMock(IBinaryFinder::class), $this->serverAccess(), $this->logger, $this->tempManager()) extends ChildProcessFinalizer {
			public string $code = '';

			protected function childCommand(string $php, array $childArgs): array {
				return [$php, '-r', $this->code, '--', ...$childArgs];
			}
		};
		// Each child appends the arguments it received and reports success.
		$finalizer->code = 'file_put_contents(' . var_export($argsFile, true) . ', json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);'
			. self::printsResult(['ok' => true, 'appId' => 'openregister']);

		$this->assertSame('openregister', $finalizer->run('/apps/openregister', ['id' => 'openregister'], 'no', 'github:o/r'));
		$this->assertSame(
			[[ChildProcessFinalizer::PROBE_ARG], ['openregister', '/apps/openregister', 'no', 'github:o/r']],
			array_map(static fn (string $line): mixed => json_decode($line, true), file($argsFile, FILE_IGNORE_NEW_LINES) ?: []),
		);
	}

	public function testARealChildThatCannotStartAfterAGoodProbeFallsBackInProcess(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$after = self::baseConfig();
		$after['added'] = true;
		$finalizer = new class($this->createMock(IBinaryFinder::class), $this->serverAccess(), $this->logger, $this->tempManager()) extends ChildProcessFinalizer {
			public string $probeCode = '';

			protected function childCommand(string $php, array $childArgs): array {
				return $childArgs === [self::PROBE_ARG] ? [$php, '-r', $this->probeCode] : ['/nonexistent/php'];
			}
		};
		$finalizer->probeCode = $this->writesConfig($after) . self::printsResult(['ok' => true, 'probe' => true]);
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not start the finalize child'));
		$this->config->expects($this->once())->method('setSystemValues')->with(['added' => true]);
		$this->appConfig->expects($this->never())->method('clearCache');
		$this->appManager->expects($this->never())->method('clearAppsCache');

		$this->assertNull($finalizer->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore'));
		$this->assertCount(2, $this->stderrFiles, 'the probe and the real child each got a stderr file');
		$this->assertFileDoesNotExist($this->stderrFiles[0]);
		$this->assertFileDoesNotExist($this->stderrFiles[1]);
	}

	public function testTheConfigIsSyncedWhenOnlyTheProbeRan(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$after = self::baseConfig();
		$after['added'] = true;
		$this->config->expects($this->once())->method('setSystemValues')->with(['added' => true]);
		$probe = $this->writesConfig($after) . 'exit(255);';

		$this->assertNull($this->finalizerWithChild(self::printsResult(['ok' => true, 'appId' => 'openregister']), $probe)
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore'));
	}

	public function testRunReturnsNullWhenNoChildCanBeStarted(): void {
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$finalizer = new class($this->createMock(IBinaryFinder::class), $this->serverAccess(), $this->logger, $this->tempManager()) extends ChildProcessFinalizer {
			protected function childCommand(string $php, array $childArgs): array {
				return ['/nonexistent/php'];
			}
		};
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not start the finalize child'));
		$this->config->expects($this->never())->method('setSystemValues');
		$this->appConfig->expects($this->never())->method('clearCache');
		$this->appManager->expects($this->never())->method('clearAppsCache');

		$this->assertNull($finalizer->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore'));
		$this->assertCount(1, $this->stderrFiles);
		$this->assertFileDoesNotExist($this->stderrFiles[0]);
	}

	public function testRunReturnsNullWhenNoTemporaryFileIsAvailable(): void {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturn(false);
		$finalizer = new ChildProcessFinalizer($this->createMock(IBinaryFinder::class), $this->serverAccess(), $this->logger, $tempManager);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('no temporary file for the finalize child'));
		$this->config->expects($this->never())->method('setSystemValues');
		$this->appConfig->expects($this->never())->method('clearCache');
		$this->appManager->expects($this->never())->method('clearAppsCache');

		$this->assertNull($finalizer->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore'));
	}

	public function testTheRealChildCommandReachesMain(): void {
		// A server root whose lib/base.php loads the unit-only bootstrap and
		// lets InstallFinalizer resolve, so the probe passes; the real run then
		// gets an app path without appinfo/info.xml.
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$root = sys_get_temp_dir() . '/vq-fake-server-' . bin2hex(random_bytes(4));
		mkdir($root . '/lib', 0777, true);
		$tests = dirname(__DIR__, 3);
		file_put_contents($root . '/lib/base.php', '<?php' . "\n"
			. 'require ' . var_export($tests . '/bootstrap-unit-only.php', true) . ";\n"
			. 'require ' . var_export(__DIR__ . '/InstallerGoldenSupport.php', true) . ";\n"
			. '\\' . InstallerGoldenSupport::class . '::useServices([\\' . InstallFinalizer::class . '::class => (new \\ReflectionClass(\\' . InstallFinalizer::class . '::class))->newInstanceWithoutConstructor()]);' . "\n");
		$savedRoot = \OC::$SERVERROOT;
		\OC::$SERVERROOT = $root;
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->once())->method('error');

		try {
			$this->finalizer('2.1.41')->run('/does/not/exist', ['id' => 'openregister'], 'yes', 'appstore');
			$this->fail('run() must throw when the real child rejects its arguments.');
		} catch (Exception $e) {
			$this->assertSame('Bad arguments for the finalize child process.', $e->getMessage());
		} finally {
			\OC::$SERVERROOT = $savedRoot;
			InstallerGoldenSupport::removeTree($root);
		}
		$this->assertCount(2, $this->stderrFiles, 'the probe and the real child each get a stderr file');
		$this->assertFileDoesNotExist($this->stderrFiles[0]);
		$this->assertFileDoesNotExist($this->stderrFiles[1]);
	}

	public function testMainFinalizesAndReportsSuccess(): void {
		$calls = [];
		$finalizer = $this->createMock(InstallFinalizer::class);
		$finalizer->expects($this->once())->method('finalize')->willReturnCallback(function (mixed ...$args) use (&$calls): string {
			$calls[] = $args;

			return 'openregister';
		});
		$appPath = $this->appWithInfoXml();
		$this->appManager->method('getAppInfoByPath')->with($appPath . '/appinfo/info.xml')->willReturn(['id' => 'openregister']);
		$this->expectOutputRegex('/^VERSIONIQ_FINALIZE_RESULT \{"ok":true/');

		$exitCode = $this->withServices([IAppManager::class => $this->appManager, InstallFinalizer::class => $finalizer], static fn (): int => ChildProcessFinalizer::main(['openregister', $appPath, 'yes', 'github:o/r']));

		$this->assertSame(0, $exitCode);
		$this->assertSame([[$appPath, ['id' => 'openregister'], 'yes', null, 'github:o/r']], $calls);
	}

	public function testMainReportsAFinalizeThatThrows(): void {
		$finalizer = $this->createMock(InstallFinalizer::class);
		$finalizer->method('finalize')->willThrowException(new RuntimeException('Repair step failed'));
		$appPath = $this->appWithInfoXml();
		$this->appManager->method('getAppInfoByPath')->willReturn(['id' => 'openregister']);
		$this->expectOutputRegex('/^VERSIONIQ_FINALIZE_RESULT \{"ok":false,"class":"RuntimeException","message":"Repair step failed"\}$/');

		$exitCode = $this->withServices([IAppManager::class => $this->appManager, InstallFinalizer::class => $finalizer], static fn (): int => ChildProcessFinalizer::main(['openregister', $appPath, 'yes', 'github:o/r']));

		$this->assertSame(1, $exitCode);
	}

	public function testTheChildRefusesAnInfoXmlThatIsNotTheAppsOwn(): void {
		// A stale or swapped-in folder: the path exists and parses, but it
		// describes another app, so the child must not finalize anything.
		$finalizer = $this->createMock(InstallFinalizer::class);
		$finalizer->expects($this->never())->method('finalize');
		$appPath = $this->appWithInfoXml();
		$this->appManager->method('getAppInfoByPath')->willReturn(['id' => 'otherapp']);
		$this->expectOutputString(self::RESULT_PREFIX . json_encode([
			'ok' => false,
			'class' => Exception::class,
			'message' => 'appinfo/info.xml of openregister could not be read in the child process.',
		], JSON_UNESCAPED_SLASHES) . "\n");

		$services = [IAppManager::class => $this->appManager, InstallFinalizer::class => $finalizer];
		$exit = $this->withServices($services, static fn (): int => ChildProcessFinalizer::main(['openregister', $appPath, 'yes', 'github:o/r']));

		$this->assertSame(1, $exit);
	}

	public function testTheConfigSyncIsSkippedWithoutAConfigDirectory(): void {
		// Nothing booted a server in this process, so \OC::$configDir is unset.
		\OC::$configDir = null;
		$this->config->expects($this->never())->method('setSystemValues');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not read config/config.php, or it has no `version` key'));

		$appId = $this->finalizerWithChild(self::printsResult(['ok' => true, 'appId' => 'openregister']))
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertSame('openregister', $appId);
	}

	public function testAConfigFileThatDoesNotParseSkipsTheSyncWithAWarning(): void {
		// A child killed inside Config::writeData() can leave a file `include`
		// refuses; that ParseError must not escape into the finalize.
		file_put_contents($this->configDir . 'config.php', "<?php\n\$CONFIG = [ ;\n");
		$this->config->expects($this->never())->method('setSystemValues');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not read config/config.php, or it has no `version` key'));

		$appId = $this->finalizerWithChild(self::printsResult(['ok' => true, 'appId' => 'openregister']))
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertSame('openregister', $appId);
	}

	public function testTheProbeReportsOkWhenInstallFinalizerResolves(): void {
		$finalizer = $this->createMock(InstallFinalizer::class);
		$finalizer->expects($this->never())->method('finalize');
		$this->expectOutputRegex('/^VERSIONIQ_FINALIZE_RESULT \{"ok":true/');

		$this->assertSame(0, $this->withServices([InstallFinalizer::class => $finalizer], static fn (): int => ChildProcessFinalizer::main([ChildProcessFinalizer::PROBE_ARG])));
	}

	public function testTheProbeFailsWhenInstallFinalizerCannotBeResolved(): void {
		$this->expectOutputRegex('/^VERSIONIQ_FINALIZE_RESULT \{"ok":false,.*No test service for/');

		$this->assertSame(1, $this->withServices([], static fn (): int => ChildProcessFinalizer::main([ChildProcessFinalizer::PROBE_ARG])));
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
		$this->assertCount(2, $this->stderrFiles, 'the probe and the real child each get a stderr file');
		$this->assertFileDoesNotExist($this->stderrFiles[0]);
		$this->assertFileDoesNotExist($this->stderrFiles[1]);
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
		$this->assertCount(2, $this->stderrFiles);
		$this->assertFileDoesNotExist($this->stderrFiles[0]);
		$this->assertFileDoesNotExist($this->stderrFiles[1]);
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

	public function testAnEmptiedConfigFileSkipsTheSyncWithAWarning(): void {
		// A child killed inside Config::writeData() leaves a truncated file.
		self::writeConfigFile($this->configDir . 'config.php', self::baseConfig());
		$this->config->expects($this->never())->method('setSystemValues');
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not read config/config.php, or it has no `version` key'));
		$empties = 'file_put_contents(' . var_export($this->configDir . 'config.php', true) . ', "");';

		$appId = $this->finalizerWithChild($empties . self::printsResult(['ok' => true, 'appId' => 'openregister']))
			->run('/apps/openregister', ['id' => 'openregister'], 'yes', 'appstore');

		$this->assertSame('openregister', $appId);
	}

	public function testAnUnreadableConfigFileSkipsTheSyncWithAWarning(): void {
		// No config.php in the config dir.
		$this->config->expects($this->never())->method('setSystemValues');
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not read config/config.php, or it has no `version` key'));

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

	public function testFinalizeWarnsWhenNoChildFinalizerIsAvailableForAnUpdate(): void {
		$this->appConfig->method('getValueString')->willReturn('2.1.41');
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('no child finalizer available'),
			['app' => 'openregister'],
		);

		$this->assertFinalizesInProcess($this->installFinalizer(null));
	}

	public function testFinalizeWithoutAChildFinalizerStaysSilentForAFreshInstall(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->logger->expects($this->never())->method('warning');

		$this->assertFinalizesInProcess($this->installFinalizer(null));
	}

	private function installFinalizer(?ChildProcessFinalizer $child): InstallFinalizer {
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
	 * An app folder with an `appinfo/info.xml`, removed again in tearDown().
	 */
	private function appWithInfoXml(): string {
		$appPath = $this->configDir . 'app';
		mkdir($appPath . '/appinfo', 0777, true);
		file_put_contents($appPath . '/appinfo/info.xml', '<info><id>openregister</id></info>');

		return $appPath;
	}

	/**
	 * Calls `$run` with `OCP\Server::get()` pointed at `$services`.
	 *
	 * @param array<string, object> $services
	 * @param callable(): int $run
	 */
	private function withServices(array $services, callable $run): int {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('Replaces the server container, which only the unit-only bootstrap allows.');
		}

		$savedServer = \OC::$server;
		InstallerGoldenSupport::useServices($services);
		try {
			return $run();
		} finally {
			\OC::$server = $savedServer;
		}
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
