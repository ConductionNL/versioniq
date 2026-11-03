<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use Exception;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden test for ForgeReleaseSource's forge request and release-asset
 * selection: performFetch() over every forge, token and response shape
 * (transport errors, each non-200 status, malformed JSON, a non-list payload,
 * a list), and buildReleasePayload() over every asset shape. The returned
 * array, every request, log line and connection report are compared, case by
 * case as JSON text, with fixtures/forge-fetch.golden.json. Set
 * RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\Source\ForgeReleaseSource
 * @uses \OCA\Versioniq\Service\Source\Forge
 * @uses \OCA\Versioniq\Service\Source\ForgeRegistry
 * @uses \OCA\Versioniq\Service\Pat\PatAccess
 */
final class ForgeFetchGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/forge-fetch.golden.json';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * Response shapes, each a status and a body (null body: a transport error
	 * with that message).
	 *
	 * @return array<string, array{0: int, 1: ?string, 2?: string}>
	 */
	private static function shapes(): array {
		return [
			'throw' => [0, null, 'Connection reset.'],
			'throw-rate' => [0, null, 'API Rate Limit hit'],
			'throw-dns' => [0, null, 'cURL error 6: Could not resolve host: api.github.com'],
			'http-401' => [401, '{}'],
			'http-403' => [403, '{}'],
			'http-404' => [404, ''],
			'http-500' => [500, '[]'],
			'http-302' => [302, '[]'],
			'http-100' => [100, '[]'],
			'http-204' => [204, '[]'],
			'badjson' => [200, '[{"tag_name": '],
			'deep' => [200, str_repeat('[', 40) . str_repeat(']', 40)],
			'scalar' => [200, '"text"'],
			'object' => [200, '{"tag_name": "v1.0.0"}'],
			'empty' => [200, '[]'],
			'list' => [200, '[{"tag_name": "v1.0.0"}, {"tag_name": "2.0.0", "body": "x"}]'],
		];
	}

	public function testEveryCaseMatchesTheGolden(): void {
		$actual = [];
		foreach (['github', 'codeberg', 'forgejo'] as $forgeId) {
			foreach ([null, 'tok-123'] as $token) {
				foreach (array_keys(self::shapes()) as $shape) {
					$actual["fetch {$forgeId} " . ($token ?? 'anon') . " {$shape}"] = $this->runFetch($forgeId, $token, $shape);
				}
			}
		}
		foreach ([true, false] as $allowLocal) {
			$actual['fetch allow-local ' . ($allowLocal ? 'on' : 'off')] = $this->runFetch('github', null, 'empty', $allowLocal);
		}
		foreach (self::releases() as $name => [$release, $pattern]) {
			$actual["payload {$name}"] = $this->runPayload($release, $pattern);
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
	 * Releases and asset patterns for buildReleasePayload().
	 *
	 * @return array<string, array{0: array<array-key, mixed>, 1: string}>
	 */
	private static function releases(): array {
		$asset = static fn (mixed $name, mixed $url = 'https://dl.example/x'): array => ['name' => $name, 'browser_download_url' => $url];
		$one = [$asset('app-1.0.0.tar.gz', 'https://dl.example/app.tgz')];

		return [
			'no assets key' => [['tag_name' => 'v1.0.0'], '*.tar.gz'],
			'assets not array' => [['assets' => 'x', 'tag_name' => 'v1'], '*.tar.gz'],
			'assets map' => [['assets' => ['a' => $one[0]], 'tag_name' => 'v1'], '*.tar.gz'],
			'assets empty' => [['assets' => [], 'tag_name' => 'v1'], '*.tar.gz'],
			'one match v tag' => [['assets' => $one, 'tag_name' => 'v1.0.0'], '*.tar.gz'],
			'one match V tag' => [['assets' => $one, 'tag_name' => 'V2.0'], '*.tar.gz'],
			'one match plain tag' => [['assets' => $one, 'tag_name' => '3.0'], '*.tar.gz'],
			'one match no tag' => [['assets' => $one], '*.tar.gz'],
			'one match int tag' => [['assets' => $one, 'tag_name' => 7], '*.tar.gz'],
			'no match' => [['assets' => $one, 'tag_name' => 'v1'], '*.zip'],
			'two matches' => [['assets' => [$asset('a.tar.gz'), $asset('b.tar.gz'), $asset('c.zip')], 'tag_name' => 'v1'], '*.tar.gz'],
			'sha sibling' => [['assets' => [$asset('a.tar.gz', 'u1'), $asset('a.tar.gz.sha256', 'u2')], 'tag_name' => 'v1'], '*.tar.gz'],
			'sha last wins' => [['assets' => [$asset('x.sha256', 's1'), $asset('a.tar.gz', 'u1'), $asset('y.sha256', 's2')], 'tag_name' => 'v1'], 'a.*'],
			'sha also matches' => [['assets' => [$asset('a.sha256', 's1')], 'tag_name' => 'v1'], '*'],
			'sha only' => [['assets' => [$asset('a.sha256', 's1')], 'tag_name' => 'v1'], '*.tar.gz'],
			'junk assets' => [['assets' => ['str', 5, null, [], $asset(''), $asset('a.tar.gz', ''), $asset(7), $asset('a.tar.gz', 9), ['name' => 'a.tar.gz'], ['browser_download_url' => 'u'], $asset('ok.tar.gz', 'u9')], 'tag_name' => 'v1'], '*.tar.gz'],
			'junk sha' => [['assets' => [$asset('a.sha256', ''), $asset('b.tar.gz', 'u')], 'tag_name' => 'v1'], '*.tar.gz'],
			'pattern backslash' => [['assets' => [$asset('a\\b.tar.gz', 'u')], 'tag_name' => 'v1'], 'a\\b.tar.gz'],
			'pattern brackets' => [['assets' => [$asset('a1.tgz', 'u'), $asset('a2.tgz', 'u2')], 'tag_name' => 'v1'], 'a[1].tgz'],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function runFetch(string $forgeId, ?string $token, string $shapeName, bool $allowLocal = false): array {
		$this->calls = [];
		$shape = self::shapes()[$shapeName];

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($shape): IResponse {
			$this->calls[] = ['http.get', $url, $options];
			if ($shape[1] === null) {
				throw new Exception($shape[2] ?? '');
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($shape[0]);
			$response->method('getBody')->willReturn($shape[1]);

			return $response;
		});

		$source = $this->source($client, $allowLocal);
		$forge = $this->registry()->get($forgeId);
		$result = (new ReflectionMethod($source, 'performFetch'))->invoke($source, $forge, $forge->releasesEndpoint('owner/repo'), $token);

		return ['result' => $result, 'calls' => $this->calls];
	}

	/**
	 * @param array<array-key, mixed> $release
	 * @return array<string, mixed>
	 */
	private function runPayload(array $release, string $pattern): array {
		$this->calls = [];
		$source = $this->source($this->createMock(IClient::class), false);
		$result = (new ReflectionMethod($source, 'buildReleasePayload'))->invoke($source, $release, $pattern);

		return ['result' => $result, 'calls' => $this->calls];
	}

	private function registry(): ForgeRegistry {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'forge.forgejo.web_base' ? 'https://git.example.org' : $default
		);

		return new ForgeRegistry($appConfig);
	}

	private function source(IClient $client, bool $allowLocal): ForgeReleaseSource {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturnCallback(function () use ($client): IClient {
			$this->calls[] = ['http.newClient'];

			return $client;
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['log.warning', $message, $context];
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(function (string $key, bool $default = false) use ($allowLocal): bool {
			$this->calls[] = ['config.bool', $key, $default];

			return $allowLocal;
		});

		$reports = $this->createMock(ConnectionReportService::class);
		$reports->method('forgeAnswered')->willReturnCallback(function (string $forgeId, string $apiBaseUrl, ?int $status, bool $readable = true): bool {
			$this->calls[] = ['report.forgeAnswered', $forgeId, $apiBaseUrl, $status, $readable];

			return true;
		});

		return $this->buildByName(ForgeReleaseSource::class, [
			'clientService' => $clientService,
			'logger' => $logger,
			'forgeRegistry' => $this->registry(),
			'config' => $config,
			'connectionReports' => $reports,
		]);
	}
}
