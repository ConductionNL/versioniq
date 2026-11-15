<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use Exception;
use OCA\Versioniq\Service\Discovery\AppStoreDiscovery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden over AppStoreDiscovery::loadCatalog(): the catalogue it returns and
 * every cache read, cache write, fetch and warning, per cache state and
 * response body, compared with fixtures/appstore-catalog.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\Discovery\AppStoreDiscovery
 */
final class AppStoreCatalogGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/appstore-catalog.golden.json';
	private const NOW = 2_000_000_000;

	/** @var list<list<mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{ts: int, cache: string, status?: int, body?: string, throws?: bool}>
	 */
	private static function cases(): array {
		$apps = json_encode([['id' => 'notes', 'name' => 'Notes', 'version' => '4.0.0', 'extra' => 'dropped'], 'junk', ['id' => 'mail']]);

		return [
			'fresh cache' => ['ts' => self::NOW - 60, 'cache' => '[{"id":"cached"},"junk"]'],
			'cache at ttl' => ['ts' => self::NOW - 3600, 'cache' => '[{"id":"cached"}]', 'body' => $apps],
			'no timestamp' => ['ts' => 0, 'cache' => '[{"id":"cached"}]', 'body' => $apps],
			'empty cache' => ['ts' => self::NOW - 60, 'cache' => '', 'body' => $apps],
			'broken cache' => ['ts' => self::NOW - 60, 'cache' => '{not json', 'body' => $apps],
			'cache not a list' => ['ts' => self::NOW - 60, 'cache' => '"x"', 'body' => $apps],
			'oversized cache' => ['ts' => self::NOW - 60, 'cache' => str_repeat('x', 4_000_001), 'body' => $apps],
			'envelope apps' => ['ts' => 0, 'cache' => '', 'body' => (string)json_encode(['apps' => [['id' => 'a']], 'data' => [['id' => 'd']]])],
			'envelope data' => ['ts' => 0, 'cache' => '', 'body' => (string)json_encode(['data' => [['id' => 'd'], 3]])],
			'apps not a list falls to data' => ['ts' => 0, 'cache' => '', 'body' => (string)json_encode(['apps' => 'x', 'data' => [['id' => 'd']]])],
			'unknown envelope' => ['ts' => 0, 'cache' => '', 'body' => (string)json_encode(['items' => []])],
			'body not json' => ['ts' => 0, 'cache' => '', 'body' => 'nope'],
			'body scalar' => ['ts' => 0, 'cache' => '', 'body' => '5'],
			'http 500' => ['ts' => 0, 'cache' => '', 'status' => 500, 'body' => $apps],
			'fetch throws' => ['ts' => 0, 'cache' => '', 'throws' => true],
			'projection too large' => ['ts' => 0, 'cache' => '', 'body' => (string)json_encode([['id' => 'big', 'description' => str_repeat('d', 4_000_000)]])],
		];
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
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		$this->calls = [];
		$result = (new ReflectionMethod(AppStoreDiscovery::class, 'loadCatalog'))->invoke($this->discovery(self::cases()[$name]));
		$summary = is_array($result) ? array_map(static function (array $app): string {
			$json = (string)json_encode($app);

			return strlen($json) > 200 ? strlen($json) . ' bytes' : $json;
		}, $result) : $result;
		$actual = json_encode(['result' => $summary, 'calls' => $this->calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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
	 * @param array{ts: int, cache: string, status?: int, body?: string, throws?: bool} $case
	 */
	private function discovery(array $case): AppStoreDiscovery {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(function (string $app, string $key, int $default = 0) use ($case): int {
			$this->calls[] = ['getInt', $key];

			return $key === 'cache.appstore_catalog_ts' ? $case['ts'] : $default;
		});
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '') use ($case): string {
			$this->calls[] = ['getString', $key];

			return $key === 'cache.appstore_catalog' ? $case['cache'] : $default;
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->calls[] = ['setString', $key, strlen($value) > 200 ? strlen($value) . ' bytes' : $value];

			return true;
		});
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->calls[] = ['setInt', $key, $value];

			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			$this->calls[] = ['delete', $key];
		});

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($case): IResponse {
			$this->calls[] = ['get', $url];
			if ($case['throws'] ?? false) {
				throw new Exception('offline');
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($case['status'] ?? 200);
			$response->method('getBody')->willReturn($case['body'] ?? '');

			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['warning', $message, $context];
		});

		return new AppStoreDiscovery($clientService, $config, $time, $logger);
	}
}
