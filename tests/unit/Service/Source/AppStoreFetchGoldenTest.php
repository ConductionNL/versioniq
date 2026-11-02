<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use Exception;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Golden test for AppStoreSource's uncached catalogue fetch: the filtered
 * endpoint pages, then the platform endpoint pages, with every response shape
 * (transport error, non-200, empty body, non-array JSON, invalid JSON, a page
 * with or without the app and with each next-page signal). Every request, every
 * app-config read and write, the returned payload and the connection outcome
 * are compared, case by case as JSON text, with
 * fixtures/appstore-fetch.golden.json. Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Service\Source\AppStoreSource
 * @uses \OCA\Versioniq\Service\Source\CatalogueQuery
 */
final class AppStoreFetchGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/appstore-fetch.golden.json';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * Response shapes, each a status and a body (null body: a transport error).
	 *
	 * @return array<string, array{0: int, 1: ?string}>
	 */
	private static function shapes(): array {
		$json = static fn (mixed $value): string => (string)json_encode($value);

		return [
			'throw' => [0, null],
			'500' => [500, '{}'],
			'404' => [404, ''],
			'blank' => [200, "  \n "],
			'scalar' => [200, '5'],
			'badjson' => [200, '{"data": ['],
			'found' => [200, $json(['data' => [['id' => 'other', 'v' => 1], ['id' => 'someapp', 'releases' => ['1.0.0']]], 'pages' => ['next' => true]])],
			'found-list' => [200, $json([['id' => 'someapp', 'releases' => []], ['id' => '']])],
			'found-apps' => [200, $json(['apps' => [['id' => 'someapp'], 'junk', ['id' => 7]]])],
			'other-next' => [200, $json(['data' => [['id' => 'other']], 'pages' => ['next' => true]])],
			'other-end' => [200, $json(['data' => [['id' => 'other']], 'pages' => ['next' => false]])],
			'other-pagination' => [200, $json(['data' => [['id' => 'other']], 'pagination' => ['next_page' => 3]])],
			'other-nextpage' => [200, $json(['apps' => [['id' => 'other']], 'nextPage' => ''])],
			'other-wrongpage' => [200, $json(['data' => [['id' => 'other']], 'page' => 9, 'pages' => ['next' => true]])],
			'empty' => [200, '[]'],
			'object-no-list' => [200, $json(['data' => ['id' => 'someapp']])],
		];
	}

	/**
	 * Each scenario: the filtered pages, the platform pages (the last entry
	 * repeats for later pages), the server version and the API base override.
	 *
	 * @return array<string, array{filtered: list<string>, platform: list<string>, version: string, base: string}>
	 */
	private static function scenarios(): array {
		$list = [];
		$add = static function (string $name, array $filtered, array $platform, string $version = '33.0.2.1', string $base = '') use (&$list): void {
			$list[$name] = ['filtered' => $filtered, 'platform' => $platform, 'version' => $version, 'base' => $base];
		};
		foreach (array_keys(self::shapes()) as $shape) {
			$add("filtered {$shape}", [$shape, 'found'], ['found']);
			$add("platform {$shape}", ['other-end'], [$shape, 'found']);
			$add("platform only {$shape}", ['other-end'], [$shape, 'other-end']);
		}
		$add('filtered pages forever', ['other-next'], ['other-end']);
		$add('both pages forever', ['other-next'], ['other-next']);
		$add('filtered errors forever', ['throw'], ['500']);
		$add('filtered non-200 forever then found', ['500'], ['found']);
		$add('found on page three', ['other-next', 'other-pagination', 'found'], ['other-end']);
		$add('platform found on page two', ['other-end'], ['other-next', 'found-list']);
		$add('odd server version', ['other-end'], ['found'], 'abc');
		$add('short server version', ['other-end'], ['found'], '34');
		$add('mirror base', ['found'], ['found'], '33.0.2.1', ' https://mirror.example/api/v1/ ');
		$add('mirror base, platform', ['other-end'], ['found'], '33.0.2.1', 'https://mirror.example/api/v1');

		return $list;
	}

	public function testEveryScenarioMatchesTheGolden(): void {
		$actual = [];
		foreach (self::scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario($scenario);
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
		self::assertSame(array_keys($expected), array_keys($actual), 'scenario list changed');
		foreach ($actual as $name => $case) {
			self::assertSame(
				json_encode($expected[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
				json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
				"scenario {$name}",
			);
		}
	}

	/**
	 * @param array{filtered: list<string>, platform: list<string>, version: string, base: string} $scenario
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->calls = [];
		$shapes = self::shapes();

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($scenario, $shapes): IResponse {
			$this->calls[] = ['http.get', $url, $options];
			$pages = str_contains($url, '/platform/') ? $scenario['platform'] : $scenario['filtered'];
			preg_match('/[?&]page=(\d+)/', $url, $m);
			$shape = $shapes[$pages[min((int)$m[1], count($pages)) - 1]];
			if ($shape[1] === null) {
				throw new Exception('Connection reset.');
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($shape[0]);
			$response->method('getBody')->willReturn($shape[1]);

			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturnCallback(function () use ($client): IClient {
			$this->calls[] = ['http.newClient'];

			return $client;
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(function (string $key, string $default = '') use ($scenario): string {
			$this->calls[] = ['config.string', $key, $default];

			return $scenario['version'];
		});

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '') use ($scenario): string {
			$this->calls[] = ['appConfig.get', $app, $key, $default];

			return $key === 'appstore.api_base' ? $scenario['base'] : $default;
		});
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) : bool {
			$this->calls[] = ['appConfig.set', $app, $key, str_contains($key, 'payload_ts') ? '<time>' : $value];

			return true;
		});

		$source = new AppStoreSource($clientService, $config, $appConfig, $this->createMock(IFactory::class));
		$result = (new ReflectionMethod($source, 'fetchAppPayloadUncached'))->invoke($source, 'someapp');
		$outcome = (new ReflectionProperty($source, 'catalogueOutcome'))->getValue($source);

		return ['result' => $result, 'outcome' => $outcome, 'calls' => $this->calls];
	}
}
