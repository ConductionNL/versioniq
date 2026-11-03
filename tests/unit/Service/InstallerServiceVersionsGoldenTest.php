<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use Exception;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for InstallerService::getAppVersions(): app ids that are blank,
 * self-managed or core, source overrides (none, same as stored, different,
 * malformed, untrusted), stored bindings, every listing outcome, installed
 * version reads, changelogs, recorded digests, cached versions and server
 * versions. The returned envelope and every collaborator call are compared,
 * case by case as JSON text, with fixtures/app-versions.golden.json. Set
 * RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\InstallerService
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 * @uses \OCA\Versioniq\Service\Source\SourceRegistry
 * @uses \OCA\Versioniq\Service\Source\AppStoreSource
 * @uses \OCA\Versioniq\Service\Source\UntrustedSourceException
 * @uses \OCA\Versioniq\Service\Installer\FailureClassifier
 */
final class InstallerServiceVersionsGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/app-versions.golden.json';

	private const STORE_MISS = 'App is not available in the Nextcloud App Store.';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$v = static fn (string $version, mixed $changelog = null, array $extra = []): array => ['version' => $version, 'changelog' => $changelog] + $extra;
		$three = ['versions' => [$v('2.0.0', 'notes'), $v('1.1.0'), $v('1.0.0', 7)], 'error' => null];
		$base = [
			'appId' => 'someapp', 'override' => null, 'stored' => null, 'untrusted' => false,
			'listing' => $three, 'shipped' => false, 'installed' => '1.0.0',
			'cached' => [], 'specs' => [], 'server' => '33.0.2.1',
		];
		$list = [];
		$add = static function (string $name, array $changes) use (&$list, $base): void {
			$list[$name] = array_replace($base, $changes);
		};

		$add('plain app store', []);
		$add('blank id', ['appId' => '']);
		$add('space id', ['appId' => "  \t"]);
		$add('padded id', ['appId' => ' someapp ']);
		$add('self managed', ['appId' => 'versioniq']);
		$add('core app', ['appId' => 'files']);
		$add('core app padded', ['appId' => ' files ']);
		$add('override appstore', ['override' => 'appstore']);
		$add('override blank', ['override' => '  ']);
		$add('override github', ['override' => 'github:acme/tool']);
		$add('override github untrusted', ['override' => 'github:acme/tool', 'untrusted' => true]);
		$add('override codeberg untrusted', ['override' => 'codeberg:acme/tool', 'untrusted' => true]);
		$add('override unknown', ['override' => 'svn:acme/tool']);
		$add('override no slash', ['override' => 'github:acme']);
		$add('override empty repo', ['override' => 'github:acme/']);
		$add('override same as stored', ['override' => 'github:acme/tool', 'stored' => 'github']);
		$add('override other than stored', ['override' => 'github:acme/other', 'stored' => 'github']);
		$add('override appstore over stored', ['override' => 'appstore', 'stored' => 'github']);
		$add('stored github', ['stored' => 'github']);
		$add('stored github untrusted', ['stored' => 'github', 'untrusted' => true]);
		$add('stored appstore', ['stored' => 'appstore']);
		$add('store miss shipped', ['listing' => ['versions' => [], 'error' => self::STORE_MISS], 'shipped' => true]);
		$add('store miss not shipped', ['listing' => ['versions' => [], 'error' => self::STORE_MISS]]);
		$add('store miss shipped with versions', ['listing' => ['versions' => [$v('1.0.0')], 'error' => self::STORE_MISS], 'shipped' => true]);
		$add('other error shipped', ['listing' => ['versions' => [], 'error' => 'GitHub API request failed.'], 'shipped' => true]);
		$add('error with versions', ['listing' => ['versions' => [$v('1.0.0')], 'error' => 'Partial listing.']]);
		$add('empty listing', ['listing' => ['versions' => [], 'error' => null]]);
		$add('installed blank', ['installed' => '']);
		$add('installed throws', ['installed' => null]);
		$add('long changelog', ['listing' => ['versions' => [$v('1.0.0', str_repeat('é', 5000)), $v('0.9.0', str_repeat('a', 8192))], 'error' => null]]);
		$add('cached, compatible spec', ['cached' => ['2.0.0', '1.0.0', '9.9.9'], 'specs' => ['2.0.0' => '>=32 <=34', '1.0.0' => '>=35']]);
		$add('cached, no spec', ['cached' => ['1.1.0']]);
		$add('cached, verdict kept', ['cached' => ['2.0.0'], 'specs' => ['2.0.0' => '>=35'], 'listing' => ['versions' => [$v('2.0.0', null, ['serverCompatible' => true])], 'error' => null]]);
		$add('cached, server blank', ['cached' => ['2.0.0'], 'specs' => ['2.0.0' => '>=32'], 'server' => '']);
		$add('cached, server odd', ['cached' => ['2.0.0'], 'specs' => ['2.0.0' => '>=32'], 'server' => '33.beta.1']);
		$add('cached, server short', ['cached' => ['2.0.0'], 'specs' => ['2.0.0' => '>=32'], 'server' => '34']);
		$add('stored github with digests, cached', ['stored' => 'github', 'cached' => ['1.1.0'], 'specs' => ['1.1.0' => '>=30']]);

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
	 * @param array<string, mixed> $case
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->calls = [];
		$service = $this->buildByName(InstallerService::class, [
			'appManager' => $this->appManager($case),
			'config' => $this->config($case),
			'sourceRegistry' => $this->sourceRegistry($case),
			'bindingStore' => $this->bindingStore($case),
			'trustedSources' => $this->trustedSources($case),
			'artifactCache' => $this->artifactCache($case),
		]);
		$result = $service->getAppVersions($case['appId'], $case['override']);

		return ['result' => $result, 'calls' => $this->calls];
	}

	private static function storedBinding(string $kind): SourceBinding {
		if ($kind === 'appstore') {
			return SourceBinding::appStore();
		}

		return SourceBinding::github('acme', 'tool')->withRecordedShaMap(['1.1.0' => str_repeat('a', 64), '2.0.0' => str_repeat('b', 64)]);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function appManager(array $case): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAlwaysEnabledApps')->willReturnCallback(function (): array {
			$this->calls[] = ['app.alwaysEnabled'];

			return ['files', 'dav'];
		});
		$appManager->method('isShipped')->willReturnCallback(function (string $appId) use ($case): bool {
			$this->calls[] = ['app.isShipped', $appId];

			return $case['shipped'];
		});
		$appManager->method('getAppVersion')->willReturnCallback(function (string $appId, bool $useCache = true) use ($case): string {
			$this->calls[] = ['app.version', $appId, $useCache];
			if ($case['installed'] === null) {
				throw new Exception('no app');
			}

			return $case['installed'];
		});

		return $appManager;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function config(array $case): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(function (string $key, string $default = '') use ($case): string {
			$this->calls[] = ['config.string', $key, $default];

			return $key === 'version' ? $case['server'] : $default;
		});

		return $config;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function sourceRegistry(array $case): SourceRegistry {
		$source = $this->createMock(SourceInterface::class);
		$source->method('listVersions')->willReturnCallback(function (string $appId, SourceBinding $binding) use ($case): array {
			$this->calls[] = ['source.listVersions', $appId, array_diff_key($binding->toArray(), ['boundAt' => true])];

			return $case['listing'];
		});
		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturnCallback(function (SourceBinding $binding) use ($source): SourceInterface {
			$this->calls[] = ['registry.get', $binding->getId()];

			return $source;
		});

		return $registry;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function bindingStore(array $case): SourceBindingStore {
		$store = $this->createMock(SourceBindingStore::class);
		$store->method('get')->willReturnCallback(function (string $appId) use ($case): ?SourceBinding {
			$this->calls[] = ['bindings.get', $appId];

			return $case['stored'] === null ? null : self::storedBinding($case['stored']);
		});

		return $store;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function trustedSources(array $case): TrustedSourceList {
		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->method('assertBindingAllowed')->willReturnCallback(function (SourceBinding $binding) use ($case): void {
			$this->calls[] = ['trusted.assert', $binding->getId()];
			if ($case['untrusted'] === true) {
				throw new UntrustedSourceException('Source ' . $binding->getId() . ' is not trusted.');
			}
		});

		return $trusted;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function artifactCache(array $case): ArtifactCache {
		$cache = $this->createMock(ArtifactCache::class);
		$cache->method('cachedVersionsFor')->willReturnCallback(function (string $appId) use ($case): array {
			$this->calls[] = ['cache.versions', $appId];

			return $case['cached'];
		});
		$cache->method('platformSpecFor')->willReturnCallback(function (string $appId, string $version) use ($case): ?string {
			$this->calls[] = ['cache.spec', $appId, $version];

			return $case['specs'][$version] ?? null;
		});

		return $cache;
	}
}
