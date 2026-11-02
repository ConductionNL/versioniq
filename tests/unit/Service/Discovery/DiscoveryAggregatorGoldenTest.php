<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use Exception;
use OCA\Versioniq\Service\Discovery\AppStoreDiscovery;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\Discovery\DiscoveryFilter;
use OCA\Versioniq\Service\Discovery\DiscoveryHit;
use OCA\Versioniq\Service\Discovery\DiscoveryProviderInterface;
use OCA\Versioniq\Service\Discovery\DiscoveryResult;
use OCA\Versioniq\Service\Discovery\GithubPrivateDiscovery;
use OCA\Versioniq\Service\Discovery\GithubSearchDiscovery;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden test for DiscoveryAggregator::search(): the three providers and the
 * app manager are recording doubles with scripted hits, errors and failures,
 * and the returned payload plus every collaborator call are compared, case
 * by case as JSON text, with fixtures/discovery-aggregator.golden.json. Set
 * RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\Discovery\DiscoveryAggregator
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryFilter
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryHit
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryMerge
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryResult
 */
final class DiscoveryAggregatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/discovery-aggregator.golden.json';

	/** @var list<array<array-key, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$store = ['ok', [
			['zeta', 'Zeta', 'Store zeta', 'https://icon/zeta.png', true],
			['alpha', 'alpha', '', null, true],
			['beta', 'Beta', 'Store beta', 'https://icon/beta.png', false],
			['gamma', 'Gamma', '', 'https://icon/gamma.png', true],
		], null];
		$private = ['ok', [
			['alpha', 'Alpha private', 'Private alpha', null, false],
			['beta', 'Beta private', 'Private beta', null, true],
			['delta', 'delta', '', null, true],
			['delta', 'Delta again', 'second', null, false],
		], 'One or more PAT-scoped searches failed (x).'];
		$public = ['ok', [
			['gamma', 'Gamma public', 'Public gamma', null, true],
			['epsilon', 'Epsilon', 'Public epsilon', null, false],
			['Zeta', 'Zeta upper', 'case differs', null, true],
		], null];
		$installed = ['alpha' => '1.0.0', 'gamma' => 'throw', 'zeta' => '3.0.0', 'other' => '9.9.9'];
		$all = ['providers' => [$store, $private, $public], 'installed' => $installed];
		$publicFirst = ['providers' => [$public, $private, $store], 'installed' => $installed];
		$cases = [];
		foreach ([null, [], ['appstore'], ['github-search', 'appstore'], ['github-private'], ['nope'], ['appstore', 'appstore']] as $ids) {
			foreach ([false, true] as $installedOnly) {
				$label = ($ids === null ? 'null' : '[' . implode(',', $ids) . ']') . ($installedOnly ? ' installed only' : '');
				$cases["all $label"] = $all + ['ids' => $ids, 'installedOnly' => $installedOnly];
			}
		}
		$cases['public first'] = $publicFirst + ['ids' => null, 'installedOnly' => false];
		$cases['private disabled'] = ['providers' => [$store, ['disabled', [], null], $public], 'installed' => $installed, 'ids' => null, 'installedOnly' => false];
		$cases['private disabled requested'] = ['providers' => [$store, ['disabled', [], null], $public], 'installed' => $installed, 'ids' => ['github-private', 'github-search'], 'installedOnly' => false];
		$cases['store throws'] = ['providers' => [['throw', [], 'App Store down'], $private, $public], 'installed' => $installed, 'ids' => null, 'installedOnly' => false];
		$cases['runtime exception is caught too'] = ['providers' => [$store, ['throwError', [], 'boom'], $public], 'installed' => $installed, 'ids' => null, 'installedOnly' => false];
		$cases['nothing installed'] = ['providers' => [$store, $private, $public], 'installed' => [], 'ids' => null, 'installedOnly' => true];
		$cases['no hits'] = ['providers' => [['ok', [], null], ['ok', [], 'failed'], ['ok', [], null]], 'installed' => $installed, 'ids' => null, 'installedOnly' => false];
		$cases['same name ordering'] = ['providers' => [['ok', [['b', 'Same', '', null, true], ['a', 'Same', '', null, true], ['c', '10', '', null, true], ['d', '9', '', null, true]], null], ['disabled', [], null], ['disabled', [], null]], 'installed' => ['c' => ''], 'ids' => null, 'installedOnly' => false];

		return $cases;
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function caseNames(): array {
		$names = [];
		foreach (array_keys(self::cases()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}

	/**
	 * @param list<string>|null $sourceIds
	 * @return array<string, mixed>
	 */
	private function runSearch(DiscoveryAggregator $aggregator, ?array $sourceIds, bool $installedOnly): array {
		return $aggregator->search('needle', new DiscoveryFilter($sourceIds, $installedOnly));
	}

	/**
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		$spec = self::cases()[$name];
		$this->calls = [];
		try {
			$result = $this->runSearch($this->aggregator($spec), $spec['ids'], $spec['installedOnly']);
		} catch (\Throwable $error) {
			$result = ['threw' => $error::class, 'message' => $error->getMessage()];
		}
		$actual = json_encode(['result' => $result, 'calls' => $this->calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private function aggregator(array $spec): DiscoveryAggregator {
		$classes = [AppStoreDiscovery::class, GithubPrivateDiscovery::class, GithubSearchDiscovery::class];
		$ids = [AppStoreDiscovery::ID, GithubPrivateDiscovery::ID, GithubSearchDiscovery::ID];
		$providers = [];
		foreach ($spec['providers'] as $index => $script) {
			$providers[] = $this->provider($classes[$index], $ids[$index], $script);
		}
		$installed = $spec['installed'];
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturnCallback(function () use ($installed): array {
			$this->calls[] = ['appManager.getEnabledApps'];

			return array_keys($installed);
		});
		$appManager->method('getAppVersion')->willReturnCallback(function (string $appId) use ($installed): string {
			$this->calls[] = ['appManager.getAppVersion', $appId];
			if ($installed[$appId] === 'throw') {
				throw new Exception('no version');
			}

			return $installed[$appId];
		});

		return new DiscoveryAggregator($appManager, ...$providers);
	}

	/**
	 * @template T of DiscoveryProviderInterface
	 * @param class-string<T> $class
	 * @param array{0: string, 1: list<array{0: string, 1: string, 2: string, 3: ?string, 4: bool}>, 2: ?string} $script
	 * @return T
	 */
	private function provider(string $class, string $id, array $script): DiscoveryProviderInterface {
		[$mode, $hits, $error] = $script;
		$provider = $this->createMock($class);
		$provider->method('getId')->willReturn($id);
		$provider->method('getLabel')->willReturn('Label ' . $id);
		$provider->method('isEnabled')->willReturnCallback(function () use ($id, $mode): bool {
			$this->calls[] = [$id . '.isEnabled'];

			return $mode !== 'disabled';
		});
		$provider->method('search')->willReturnCallback(function (string $query) use ($id, $mode, $hits, $error): DiscoveryResult {
			$this->calls[] = [$id . '.search', $query];
			if ($mode === 'throw') {
				throw new Exception((string)$error);
			}
			if ($mode === 'throwError') {
				throw new RuntimeException((string)$error);
			}

			return new DiscoveryResult(array_map(static fn (array $hit): DiscoveryHit => new DiscoveryHit(
				appId: $hit[0],
				name: $hit[1],
				summary: $hit[2],
				iconUrl: $hit[3],
				sourceProviderId: $id,
				sourceBinding: ['kind' => 'test', 'from' => $id, 'app' => $hit[0]],
				installable: $hit[4],
				installableReason: $hit[4] ? null : 'reason ' . $id,
				homepageUrl: $hit[4] ? null : 'https://home/' . $hit[0],
			), $hits), $error);
		});

		return $provider;
	}
}
