<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OC\AppFramework\Bootstrap\Coordinator;
use OC\DB\Connection;
use OCA\Versioniq\Service\Installer\ChildProcessFinalizer;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Lkg\Lkg;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Settings\ConfigStores;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/InstallerGoldenSupport.php';

/**
 * Golden test for InstallFinalizer::finalize(): fresh installs and upgrades,
 * every repair-step shape, background jobs, a legacy install script, info.xml
 * with and without a version, remote and public routes, declared app types,
 * an output sink and a step that throws. The returned app id and every call
 * finalize makes (server internals, app config, config writes, job list,
 * logger, last-known-good record) are compared, case by case as JSON text,
 * with fixtures/install-finalizer.golden.json. Set RECORD_GOLDEN=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\Versioniq\Service\Installer\InstallFinalizer
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 * @uses \OCA\Versioniq\Service\Lkg\Lkg
 * @uses \OCA\Versioniq\Service\Settings\ConfigStores
 */
final class InstallFinalizerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/install-finalizer.golden.json';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/** The record the store holds before the finalize. */
	private ?Lkg $storedLkg = null;

	/** The record the finalize wrote. */
	private ?Lkg $writtenLkg = null;

	private string $root = '';

	private mixed $savedServer = null;

	protected function setUp(): void {
		parent::setUp();
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('The golden runs on the unit-only bootstrap, whose OC_App recorder and server container it controls.');
		}

		$this->root = sys_get_temp_dir() . '/vq-finalizer-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/plain/appinfo', 0777, true);
		mkdir($this->root . '/script/appinfo', 0777, true);
		file_put_contents($this->root . '/script/appinfo/install.php', '<?php \OC_App::record("install.php", []);');
		$this->savedServer = \OC::$server;
		\OC::$server = new class {
			public function get(string $name): object {
				\OC_App::record('Server::get', [$name]);

				return match ($name) {
					Coordinator::class => new Coordinator(),
					Connection::class => new Connection(),
				};
			}
		};
		\OC_App::$recorder = function (string $call, array $args): void {
			$this->calls[] = array_merge([$call], $args);
		};
	}

	protected function tearDown(): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			parent::tearDown();

			return;
		}

		\OC_App::$recorder = null;
		\OC::$server = $this->savedServer;
		unlink($this->root . '/script/appinfo/install.php');
		foreach (['/plain/appinfo', '/plain', '/script/appinfo', '/script', ''] as $dir) {
			rmdir($this->root . $dir);
		}
		parent::tearDown();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$base = [
			'path' => 'plain', 'info' => ['id' => 'someapp', 'version' => '2.0.0'], 'enabled' => 'yes',
			'output' => false, 'sourceId' => null, 'previous' => '', 'appVersion' => '1.9.0',
			'appInfo' => ['types' => ['filesystem', 'dav']], 'throwAt' => null,
		];
		$steps = ['pre-migration' => ['OCA\\Some\\Pre'], 'post-migration' => ['OCA\\Some\\Post', 'OCA\\Some\\Post2'], 'install' => ['OCA\\Some\\Install']];
		$list = [];
		$add = static function (string $name, array $changes) use (&$list, $base): void {
			$list[$name] = array_replace($base, $changes);
		};

		$add('fresh install', []);
		$add('upgrade', ['previous' => '1.0.0']);
		$add('fresh with steps', ['info' => ['id' => 'someapp', 'version' => '2.0.0', 'repair-steps' => $steps]]);
		$add('upgrade with steps', ['previous' => '1.0.0', 'info' => ['id' => 'someapp', 'version' => '2.0.0', 'repair-steps' => $steps]]);
		$add('upgrade, empty steps', ['previous' => '1.0.0', 'info' => ['id' => 'someapp', 'version' => '2.0.0', 'repair-steps' => ['pre-migration' => [], 'post-migration' => [], 'install' => []]]]);
		$add('upgrade, scalar steps', ['previous' => '1.0.0', 'info' => ['id' => 'someapp', 'version' => '2.0.0', 'repair-steps' => ['pre-migration' => 'OCA\\One', 'post-migration' => 'OCA\\Two', 'install' => 'OCA\\Three']]]);
		$add('steps not an array', ['previous' => '1.0.0', 'info' => ['id' => 'someapp', 'version' => '2.0.0', 'repair-steps' => 'x']]);
		$add('background jobs', ['info' => ['id' => 'someapp', 'version' => '2.0.0', 'background-jobs' => ['OCA\\Some\\JobA', 'OCA\\Some\\JobB']]]);
		$add('one background job as string', ['info' => ['id' => 'someapp', 'version' => '2.0.0', 'background-jobs' => 'OCA\\Some\\JobA']]);
		$add('install script', ['path' => 'script']);
		$add('no info version', ['info' => ['id' => 'someapp']]);
		$add('empty info version', ['info' => ['id' => 'someapp', 'version' => '']]);
		$add('no id', ['info' => ['version' => '2.0.0']]);
		$add('routes', ['info' => ['id' => 'someapp', 'version' => '2.0.0', 'remote' => ['webdav' => 'appinfo/v1/webdav.php', 'caldav' => 'remote.php'], 'public' => ['share' => 'public.php']]]);
		$add('route as string', ['info' => ['id' => 'someapp', 'version' => '2.0.0', 'remote' => 'remote.php', 'public' => 'public.php']]);
		$add('types not an array', ['appInfo' => ['types' => 'filesystem']]);
		$add('no types', ['appInfo' => ['id' => 'someapp']]);
		$add('no app info', ['appInfo' => null]);
		$add('numeric types', ['appInfo' => ['types' => [1, 'x']]]);
		$add('output given', ['output' => true]);
		$add('disabled, source id', ['enabled' => 'no', 'sourceId' => 'github:acme/tool']);
		$add('everything, upgrade', ['path' => 'script', 'previous' => '1.0.0', 'output' => true, 'sourceId' => 'codeberg:a/b', 'info' => ['id' => 'someapp', 'version' => '3.0.0', 'repair-steps' => $steps, 'background-jobs' => ['OCA\\Some\\JobA'], 'remote' => ['x' => 'y.php'], 'public' => ['p' => 'q.php']]]);
		$add('migration throws', ['throwAt' => 'migrate', 'previous' => '1.0.0', 'info' => ['id' => 'someapp', 'version' => '2.0.0', 'repair-steps' => $steps]]);
		$add('job list throws', ['throwAt' => 'jobList', 'info' => ['id' => 'someapp', 'version' => '2.0.0', 'background-jobs' => ['OCA\\Some\\JobA', 'OCA\\Some\\JobB']]]);
		$add('config write throws', ['throwAt' => 'setAppValue']);

		return $list;
	}

	public function testEveryCaseMatchesTheGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => $case) {
			$actual[$name] = $this->runCase($case);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			if (!is_dir(dirname(self::FIXTURE))) {
				mkdir(dirname(self::FIXTURE));
			}
			$lines = [];
			foreach ($actual as $name => $case) {
				$lines[] = json_encode($name) . ': ' . json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('Golden fixture recorded.');
		}

		/** @var array<string, mixed> $expected */
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertSame(array_keys($expected), array_keys($actual), 'case list changed');
		foreach ($actual as $name => $case) {
			self::assertSame(
				json_encode($expected[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				"case {$name}",
			);
		}
	}

	/**
	 * Nextcloud's container could not build a child finalizer, or the service
	 * was constructed by hand: an UPDATE then finalizes in this process, where
	 * the repair steps of the new version run against the previous version's
	 * registrations (#611). That must not pass silently, so the warning is part
	 * of the contract; the finalize itself still runs to the end.
	 */
	public function testAnUpgradeWithoutAChildFinalizerWarnsAndFinalizesInProcess(): void {
		$outcome = $this->runCase([
			'path' => 'plain', 'info' => ['id' => 'someapp', 'version' => '2.0.0'], 'enabled' => 'yes',
			'output' => false, 'sourceId' => null, 'previous' => '1.0.0', 'appVersion' => '1.9.0',
			'appInfo' => ['types' => []], 'throwAt' => null, 'noChild' => true,
		]);

		self::assertSame('someapp', $outcome['returned']);
		$warnings = array_values(array_filter($outcome['calls'], static fn (array $call): bool => $call[0] === 'log.warning'));
		self::assertCount(1, $warnings);
		self::assertStringContainsString('no child finalizer available', $warnings[0][1]);
		self::assertSame(['app' => 'someapp'], $warnings[0][2]);
		// The in-process finalize still ran end to end.
		self::assertContains(['Coordinator::runLazyRegistration', 'someapp'], $outcome['calls']);
		self::assertContains(['config.setAppValue', 'someapp', 'installed_version', '2.0.0'], $outcome['calls']);
		self::assertContains(['lkg.set', 'someapp', '2.0.0', '2026-11-03T12:34:56+00:00', 'appstore'], $outcome['calls']);
	}

	/**
	 * safety-rollback-backup-and-preflight 1.2: a successful update keeps the
	 * record it replaces as `previous`, so the card can roll back one step.
	 */
	public function testASuccessfulUpdateKeepsTheReplacedRecordAsPrevious(): void {
		$this->storedLkg = new Lkg('1.0.0', '2026-10-01T08:00:00+00:00', 'github:acme/someapp');
		$outcome = $this->runCase([
			'path' => 'plain', 'info' => ['id' => 'someapp', 'version' => '2.0.0'], 'enabled' => 'yes',
			'output' => false, 'sourceId' => null, 'previous' => '1.0.0', 'appVersion' => '1.9.0',
			'appInfo' => ['types' => []], 'throwAt' => null, 'noChild' => true,
		]);

		self::assertSame('someapp', $outcome['returned']);
		self::assertSame('2.0.0', $this->writtenLkg?->version);
		self::assertSame(['version' => '1.0.0', 'recordedAt' => '2026-10-01T08:00:00+00:00', 'sourceId' => 'github:acme/someapp'], $this->writtenLkg?->previous?->toArray());
	}

	/**
	 * A FRESH install has no previous version to clash with, so the same
	 * missing child finalizer must stay silent.
	 */
	public function testAFreshInstallWithoutAChildFinalizerLogsNothing(): void {
		$outcome = $this->runCase([
			'path' => 'plain', 'info' => ['id' => 'someapp', 'version' => '2.0.0'], 'enabled' => 'yes',
			'output' => false, 'sourceId' => null, 'previous' => '', 'appVersion' => '1.9.0',
			'appInfo' => ['types' => []], 'throwAt' => null, 'noChild' => true,
		]);

		self::assertSame('someapp', $outcome['returned']);
		self::assertSame([], array_values(array_filter($outcome['calls'], static fn (array $call): bool => $call[0] === 'log.warning')));
	}

	/**
	 * @param array<string, mixed> $case
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		$finalizer = new InstallFinalizer(
			new ConfigStores($this->config($case), $this->appConfig($case)),
			$this->appManager($case),
			$this->jobList($case),
			$this->logger(),
			$this->lkgStore(),
			$this->timeFactory(),
			($case['noChild'] ?? false) === true ? null : $this->childFinalizer(),
		);
		if ($case['throwAt'] === 'migrate') {
			\OC_App::$recorder = function (string $call, array $args): void {
				$this->calls[] = array_merge([$call], $args);
				if ($call === 'MigrationService::migrate') {
					throw new RuntimeException('migration failed');
				}
			};
		}

		$args = [$this->root . '/' . $case['path'], $case['info'], $case['enabled']];
		if ($case['output'] === true || $case['sourceId'] !== null) {
			$args[] = $case['output'] === true ? $this->createMock(IOutput::class) : null;
		}
		if ($case['sourceId'] !== null) {
			$args[] = $case['sourceId'];
		}
		try {
			$result = ['returned' => $finalizer->finalize(...$args)];
		} catch (RuntimeException $error) {
			$result = ['threw' => $error->getMessage()];
		} finally {
			\OC_App::$recorder = function (string $call, array $args): void {
				$this->calls[] = array_merge([$call], $args);
			};
		}

		$calls = str_replace($this->root, '<root>', (string)json_encode($this->calls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		return $result + ['calls' => json_decode($calls, true)];
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function appConfig(array $case): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '') use ($case): string {
			$this->calls[] = ['appConfig.get', $app, $key, $default];

			return $case['previous'];
		});

		return $appConfig;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function config(array $case): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, mixed $value) use ($case): void {
			$this->calls[] = ['config.setAppValue', $app, $key, $value];
			if ($case['throwAt'] === 'setAppValue' && $key === 'enabled') {
				throw new RuntimeException('config write failed');
			}
		});

		return $config;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function appManager(array $case): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturnCallback(function (string $appId, bool $useCache = true) use ($case): string {
			$this->calls[] = ['app.version', $appId, $useCache];

			return $case['appVersion'];
		});
		$appManager->method('getAppInfo')->willReturnCallback(function (string $appId) use ($case): ?array {
			$this->calls[] = ['app.info', $appId];

			return $case['appInfo'];
		});
		$appManager->method('clearAppsCache')->willReturnCallback(function (): void {
			$this->calls[] = ['app.clearAppsCache'];
		});

		return $appManager;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function jobList(array $case): IJobList {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(function (mixed $job, mixed $argument = null) use ($case): void {
			$this->calls[] = ['jobs.add', $job, $argument];
			if ($case['throwAt'] === 'jobList') {
				throw new RuntimeException('job list failed');
			}
		});

		return $jobList;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['log.warning', $message, $context];
		});

		return $logger;
	}

	private function lkgStore(): LkgStore {
		$store = $this->createMock(LkgStore::class);
		$store->method('get')->willReturnCallback(fn (): ?Lkg => $this->storedLkg);
		$store->method('set')->willReturnCallback(function (string $appId, Lkg $lkg): void {
			$this->calls[] = ['lkg.set', $appId, $lkg->version, $lkg->recordedAt, $lkg->sourceId];
			$this->writtenLkg = $lkg;
		});

		return $store;
	}

	/**
	 * A child finalizer that takes no case: the golden pins the in-process
	 * finalize, and without a child finalizer an upgrade case would also log
	 * the "no child finalizer" warning and read installed_version once more.
	 */
	private function childFinalizer(): ChildProcessFinalizer {
		$child = $this->createMock(ChildProcessFinalizer::class);
		$child->method('applies')->willReturn(false);

		return $child;
	}

	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(function (string $when = 'now', ?\DateTimeZone $zone = null): \DateTime {
			$this->calls[] = ['time.getDateTime', $when, $zone?->getName()];

			return new \DateTime('2026-11-03 12:34:56', $zone);
		});

		return $time;
	}
}
