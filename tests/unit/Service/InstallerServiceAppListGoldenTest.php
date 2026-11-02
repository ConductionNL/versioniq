<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use Exception;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lkg\Lkg;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for InstallerService::getInstalledApps(): the app lists, app
 * metadata, environment checks, version reads, bindings and last-known-good
 * records come from recording doubles, and the returned cards plus every
 * collaborator call are compared, case by case as JSON text, with
 * fixtures/installed-apps.golden.json. Set RECORD_GOLDEN=1 to rewrite the
 * fixture.
 *
 * The metadata cases need the unit-only \OC_App stub, whose app list a test
 * can set; against a real server they are skipped. The other cases use app
 * ids no server has, so the real app list adds nothing to them.
 *
 * @covers \OCA\Versioniq\Service\InstallerService
 * @uses \OCA\Versioniq\Service\Installer\FailureClassifier
 * @uses \OCA\Versioniq\Service\Lkg\Lkg
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 */
final class InstallerServiceAppListGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/installed-apps.golden.json';

	private const BASE = [
		'enabled' => ['qfzeta', 'versioniq', 'qfalpha', 'qfcore', 'qfalpha'],
		'installed' => ['qfalpha' => '1.0.0', 'qfbeta' => '2.0.0', 'versioniq' => '9.0.0', 'qfgamma' => '3.0.0', 'qfzeta' => '0.1.0'],
		'bound' => ['qfdelta', 'qfalpha', ' versioniq ', 'qfepsilon'],
		'alwaysEnabled' => ['qfcore', 'qfalpha'],
		'allApps' => [],
		'paths' => ['qfalpha' => '/apps/qfalpha', 'qfbeta' => '/apps/qfbeta', 'qfdelta' => 'error', 'qfgamma' => '/apps/qfgamma', 'qfzeta' => '/apps/qfzeta'],
		'inspect' => [
			'/apps/qfalpha' => ['manageable' => false, 'warning' => 'Not writable.'],
			'/apps/qfbeta' => 'throw',
			'/apps/qfgamma' => ['manageable' => true, 'warning' => 'Mounted.'],
		],
		'versions' => ['qfalpha' => '1.0.1', 'qfbeta' => '', 'qfgamma' => 'exception', 'qfzeta' => 'runtime', 'qfcore' => ''],
		'shipped' => ['qfcore', 'qfgamma'],
		'bindings' => ['qfdelta' => 'github', 'qfalpha' => 'appstore'],
		'lkg' => ['qfalpha' => ['1.0.0', '2026-01-01T00:00:00+00:00', 'appstore'], 'qfgamma' => ['2.9.0', '2026-02-02T00:00:00+00:00', null]],
	];

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	private static function cases(): array {
		$meta = [
			['id' => 'qfalpha', 'name' => '  Alpha  ', 'description' => " Long text\n", 'summary' => ' Short ', 'preview' => ' https://example.test/a.png '],
			['id' => 'qfbeta', 'name' => '   ', 'description' => 5, 'summary' => null, 'preview' => ['x']],
			['id' => 'qfgamma', 'name' => 7],
			['id' => 'qfzeta', 'name' => 'Zeta', 'description' => '', 'summary' => '', 'preview' => ''],
			['name' => 'No id at all'],
			['id' => 42, 'name' => 'Numeric id'],
			['id' => 'qfunlisted', 'name' => 'Not installed, not bound'],
		];
		$list = [
			'base' => [[], false],
			'nothing installed' => [['enabled' => [], 'installed' => [], 'bound' => [], 'alwaysEnabled' => []], false],
			'only self' => [['enabled' => ['versioniq'], 'installed' => ['versioniq' => '1.0.0'], 'bound' => ['versioniq']], false],
			'no environment, no versions' => [['paths' => [], 'inspect' => [], 'versions' => []], false],
			'every path inspected' => [['paths' => ['qfalpha' => '/p', 'qfbeta' => '/p', 'qfcore' => '/p', 'qfdelta' => '/p', 'qfepsilon' => '/p', 'qfgamma' => '/p', 'qfzeta' => '/p'], 'inspect' => ['/p' => ['manageable' => true, 'warning' => null]]], false],
			'version read throws an error' => [['versions' => ['qfalpha' => 'error']], false],
			'path read throws an error' => [['paths' => ['qfalpha' => 'fatal']], false],
			'installed version listed under numeric-looking id' => [['enabled' => ['123'], 'installed' => ['123' => '1.0.0'], 'bound' => []], false],
			'numeric-looking ids compare as text' => [['enabled' => ['1e1'], 'installed' => ['10' => '1.0.0', '1e1' => '2.0.0'], 'bound' => []], false],
			'metadata' => [['allApps' => $meta], true],
			'metadata, nothing enabled' => [['allApps' => $meta, 'enabled' => []], true],
			'metadata, numeric app id' => [['allApps' => $meta, 'enabled' => ['42']], true],
			'metadata, an app listed twice' => [['allApps' => array_merge($meta, [['id' => 'qfalpha', 'name' => 'Second entry']])], true],
		];

		return $list;
	}

	public function testMatchesTheGolden(): void {
		$recorded = [];
		$stub = property_exists(\OC_App::class, 'allApps');
		foreach (self::cases() as $name => [$override, $needsStub]) {
			if ($needsStub && !$stub) {
				continue;
			}
			$recorded[$name] = $this->observe(array_merge(self::BASE, $override));
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			self::markTestIncomplete('Golden recorded.');
		}

		$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertIsArray($golden);
		foreach ($recorded as $name => $actual) {
			self::assertArrayHasKey($name, $golden, $name);
			self::assertSame(
				json_encode($golden[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
				json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
				$name,
			);
		}
		if (!$stub) {
			self::markTestSkipped('The metadata cases need the unit-only \\OC_App stub.');
		}
		self::assertSame(array_keys($golden), array_keys($recorded));
	}

	/**
	 * @param array<string, mixed> $case
	 * @return array<string, mixed>
	 */
	private function observe(array $case): array {
		$this->calls = [];
		if (property_exists(\OC_App::class, 'allApps')) {
			\OC_App::$allApps = $case['allApps'];
		}
		try {
			$result = ['apps' => $this->service($case)->getInstalledApps()];
		} catch (\Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		} finally {
			if (property_exists(\OC_App::class, 'allApps')) {
				\OC_App::$allApps = [];
			}
		}

		return $result + ['calls' => $this->calls];
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function service(array $case): InstallerService {
		$service = $this->buildByName(InstallerService::class, [
			'appManager' => $this->appManager($case),
			'config' => $this->createMock(IConfig::class),
			'appConfig' => $this->createMock(IAppConfig::class),
			'sourceRegistry' => $this->createMock(SourceRegistry::class),
			'bindingStore' => $this->bindingStore($case),
			'trustedSources' => $this->createMock(TrustedSourceList::class),
			'signedInstaller' => $this->createMock(SelectedReleaseInstallerService::class),
			'externalInstaller' => $this->createMock(ExternalReleaseInstallerService::class),
			'failureClassifier' => new FailureClassifier($this->createMock(IFactory::class)),
			'environmentCheck' => $this->environmentCheck($case),
			'pinStore' => $this->createMock(PinStore::class),
			'userSession' => $this->createMock(IUserSession::class),
			'timeFactory' => $this->createMock(ITimeFactory::class),
			'lkgStore' => $this->lkgStore($case),
			'artifactCache' => $this->createMock(ArtifactCache::class),
			'eventDispatcher' => $this->createMock(IEventDispatcher::class),
		]);

		return $service;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function appManager(array $case): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturnCallback(function () use ($case): array {
			$this->calls[] = ['getEnabledApps'];
			return $case['enabled'];
		});
		$appManager->method('getAppInstalledVersions')->willReturnCallback(function (bool $onlyEnabled) use ($case): array {
			$this->calls[] = ['getAppInstalledVersions', $onlyEnabled];
			return $case['installed'];
		});
		$appManager->method('getAlwaysEnabledApps')->willReturnCallback(function () use ($case): array {
			$this->calls[] = ['getAlwaysEnabledApps'];
			return $case['alwaysEnabled'];
		});
		$appManager->method('getAppPath')->willReturnCallback(function (string $appId) use ($case): string {
			$this->calls[] = ['getAppPath', $appId];
			$path = $case['paths'][$appId] ?? 'error';
			if ($path === 'error') {
				throw new AppPathNotFoundException('no path for ' . $appId);
			}
			if ($path === 'fatal') {
				throw new \Error('fatal path ' . $appId);
			}
			return $path;
		});
		$appManager->method('getAppVersion')->willReturnCallback(function (string $appId, bool $useCache) use ($case): string {
			$this->calls[] = ['getAppVersion', $appId, $useCache];
			$version = $case['versions'][$appId] ?? '';
			return match ($version) {
				'exception' => throw new Exception('version ' . $appId),
				'runtime' => throw new RuntimeException('runtime ' . $appId),
				'error' => throw new \Error('error ' . $appId),
				default => $version,
			};
		});
		$appManager->method('isShipped')->willReturnCallback(function (string $appId) use ($case): bool {
			$this->calls[] = ['isShipped', $appId];
			return in_array($appId, $case['shipped'], true);
		});

		return $appManager;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function bindingStore(array $case): SourceBindingStore {
		$store = $this->createMock(SourceBindingStore::class);
		$store->method('listBoundAppIds')->willReturnCallback(function () use ($case): array {
			$this->calls[] = ['listBoundAppIds'];
			return $case['bound'];
		});
		$store->method('get')->willReturnCallback(function (string $appId) use ($case): ?SourceBinding {
			$this->calls[] = ['binding', $appId];
			return match ($case['bindings'][$appId] ?? null) {
				'github' => SourceBinding::github('ConductionNL', $appId),
				'appstore' => SourceBinding::appStore(),
				default => null,
			};
		});

		return $store;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function environmentCheck(array $case): EnvironmentCheck {
		$check = $this->createMock(EnvironmentCheck::class);
		$check->method('inspect')->willReturnCallback(function (string $path) use ($case): array {
			$this->calls[] = ['inspect', $path];
			$result = $case['inspect'][$path] ?? ['manageable' => true, 'warning' => 'default for ' . $path];
			if ($result === 'throw') {
				throw new RuntimeException('inspect failed');
			}
			return $result;
		});

		return $check;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function lkgStore(array $case): LkgStore {
		$store = $this->createMock(LkgStore::class);
		$store->method('get')->willReturnCallback(function (string $appId) use ($case): ?Lkg {
			$this->calls[] = ['lkg', $appId];
			$record = $case['lkg'][$appId] ?? null;
			return $record === null ? null : new Lkg(...$record);
		});

		return $store;
	}
}
