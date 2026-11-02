<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use Exception;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Discovery\DiscoveryResult;
use OCA\Versioniq\Service\Discovery\GithubPrivateDiscovery;
use OCA\Versioniq\Service\Discovery\GithubSearchDiscovery;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for GithubSearchDiscovery::search() and
 * GithubPrivateDiscovery::search(): scripted HTTP responses, PATs, allowlist
 * and user session come from recording doubles, and the returned result plus
 * every collaborator call are compared, case by case as JSON text, with
 * fixtures/github-discovery.golden.json. Set RECORD_GOLDEN=1 to rewrite the
 * fixture.
 *
 * @covers \OCA\Versioniq\Service\Discovery\GithubSearchDiscovery
 * @covers \OCA\Versioniq\Service\Discovery\GithubPrivateDiscovery
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryHit
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryResult
 * @uses \OCA\Versioniq\Db\Pat
 */
final class GithubDiscoveryGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/github-discovery.golden.json';

	private const ITEMS = [
		'not an array',
		['full_name' => 'Trusted/my-App', 'name' => 'my-App', 'description' => 'An app', 'html_url' => 'https://github.com/Trusted/my-App'],
		['full_name' => 'Other/plain', 'name' => 7, 'description' => null, 'html_url' => 5],
		['name' => 'no-full-name'],
		['full_name' => 42],
		['full_name' => 'noslash'],
		['full_name' => 'Trusted/deep/path-x'],
		['full_name' => '/leading'],
		['full_name' => 'trailing/'],
		['full_name' => 'Trusted/my-App', 'name' => 'dup'],
		[],
	];

	/** @var list<array<array-key, mixed>> */
	private array $calls = [];

	/** @var list<array<array-key, mixed>|string> */
	private array $responses = [];

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	private static function cases(): array {
		$items = (string)json_encode(['items' => self::ITEMS]);
		$deep = str_repeat('[', 40) . str_repeat(']', 40);
		$http = [
			'ok items' => [[200, $items]],
			'ok empty items' => [[200, '{"items":[]}']],
			'ok no items' => [[200, '{"total_count":0}']],
			'ok items not list' => [[200, '{"items":"x"}']],
			'ok scalar' => [[200, '5']],
			'ok malformed' => [[200, '{oops']],
			'ok too deep' => [[200, $deep]],
			'rate limited' => [[403, '{}']],
			'server error' => [[500, $items]],
			'redirect' => [[301, $items]],
			'unreachable' => ['throw'],
			'status unreadable' => ['statusThrow'],
			'body unreadable' => ['bodyThrow'],
			'client error' => ['error'],
		];
		$cases = [];
		$cases['search disabled'] = ['search', ['enabled' => 'false', 'query' => 'calendar', 'http' => [[200, $items]]]];
		$cases['search enabled unset'] = ['search', ['enabled' => null, 'query' => 'calendar', 'http' => [[200, $items]]]];
		$cases['search empty query'] = ['search', ['query' => '', 'http' => [[200, $items]]]];
		$cases['search blank query'] = ['search', ['query' => "  \t ", 'http' => [[200, $items]]]];
		foreach ($http as $name => $responses) {
			$cases["search $name"] = ['search', ['query' => '  my app ', 'http' => $responses]];
		}

		$cases['private no user'] = ['private', ['user' => null, 'pats' => ['Trusted/*'], 'http' => [[200, $items]]]];
		$cases['private no pats'] = ['private', ['pats' => [], 'http' => [[200, $items]]]];
		foreach (['*', '', '*/*', ' Trusted/* ', 'Tr*/*', '/*', 'Trusted/my-App', 'Tr*/x', 'plain'] as $pattern) {
			$cases["private pattern '$pattern'"] = ['private', ['pats' => [$pattern], 'query' => ' tool ', 'http' => [[200, $items]]]];
		}
		foreach ($http as $name => $responses) {
			$cases["private $name"] = ['private', ['pats' => ['Trusted/*'], 'query' => 'x', 'http' => $responses]];
		}
		$cases['private two pats dedupe'] = ['private', ['pats' => ['Trusted/*', 'Other/*'], 'query' => 'x', 'http' => [[200, $items], [200, $items]]]];
		$cases['private token failure only'] = ['private', ['pats' => ['Fail/*'], 'query' => 'x', 'http' => []]];
		$cases['private token failure and hits'] = ['private', ['pats' => ['Fail/*', 'Trusted/*'], 'query' => 'x', 'http' => [[200, $items]]]];
		$cases['private token failure and none'] = ['private', ['pats' => ['Trusted/*', 'Fail/*'], 'query' => 'x', 'http' => [[200, '{"items":[]}']]]];
		$cases['private second pat body unreadable'] = ['private', ['pats' => ['Trusted/*', 'Other/*'], 'query' => 'x', 'http' => [[200, $items], 'bodyThrow']]];
		$cases['private second pat fails http'] = ['private', ['pats' => ['Trusted/*', 'Other/*'], 'query' => 'x', 'http' => [[200, $items], 'throw']]];

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
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		[$provider, $spec] = self::cases()[$name];
		$this->calls = [];
		$this->responses = $spec['http'];
		$query = $spec['query'] ?? 'calendar';
		try {
			$result = self::export($provider === 'search'
				? $this->searchDiscovery(array_key_exists('enabled', $spec) ? $spec['enabled'] : 'true')->search($query)
				: $this->privateDiscovery($spec)->search($query));
		} catch (\Throwable $error) {
			$result = ['threw' => $error::class, 'message' => $error->getMessage()];
		}
		$actual = json_encode(['result' => $result, 'calls' => $this->calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function export(DiscoveryResult $result): array {
		return [
			'hits' => array_map(static fn ($hit): array => $hit->toArray(), $result->hits),
			'error' => $result->error,
		];
	}

	private function searchDiscovery(?string $enabled): GithubSearchDiscovery {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '') use ($enabled): string {
			$this->calls[] = ['config.getValueString', $app, $key, $default];

			return $enabled ?? $default;
		});

		return new GithubSearchDiscovery($config, $this->trustedSources(), $this->clientService(), $this->logger());
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private function privateDiscovery(array $spec): GithubPrivateDiscovery {
		$pats = [];
		foreach ($spec['pats'] as $index => $pattern) {
			$pat = new Pat();
			$pat->setId(str_starts_with($pattern, 'Fail/') ? 99 : $index + 1);
			$pat->setTargetPattern($pattern);
			$pats[] = $pat;
		}
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findVisibleTo')->willReturnCallback(function (string $uid) use ($pats): array {
			$this->calls[] = ['patMapper.findVisibleTo', $uid];

			return $pats;
		});
		$patManager = $this->createMock(PatManager::class);
		$patManager->method('useToken')->willReturnCallback(function (Pat $pat, callable $callback): mixed {
			$this->calls[] = ['patManager.useToken', $pat->getId()];
			if ($pat->getId() === 99) {
				throw new Exception('decrypt failed');
			}

			return $callback('token-' . $pat->getId());
		});
		$session = $this->createMock(IUserSession::class);
		$uid = array_key_exists('user', $spec) ? $spec['user'] : 'admin';
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);

		return new GithubPrivateDiscovery($mapper, $patManager, $this->trustedSources(), $session, $this->clientService(), $this->logger());
	}

	private function trustedSources(): TrustedSourceList {
		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->method('isAllowed')->willReturnCallback(function (string $sourceId): bool {
			$this->calls[] = ['trustedSources.isAllowed', $sourceId];

			return str_starts_with($sourceId, 'github:Trusted/');
		});

		return $trusted;
	}

	private function clientService(): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $uri, array $options = []): IResponse {
			$this->calls[] = ['client.get', $uri, $options];
			$next = array_shift($this->responses) ?? [404, ''];
			if ($next === 'throw') {
				throw new Exception('connection refused');
			}
			if ($next === 'error') {
				throw new \Error('client broken');
			}
			$response = $this->createMock(IResponse::class);
			if ($next === 'statusThrow') {
				$response->method('getStatusCode')->willThrowException(new Exception('status unreadable'));
			} elseif ($next === 'bodyThrow') {
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willThrowException(new RuntimeException('body unreadable'));
			} else {
				$response->method('getStatusCode')->willReturn($next[0]);
				$response->method('getBody')->willReturn($next[1]);
			}

			return $response;
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			$this->calls[] = ['logger.warning', (string)$message, $context];
		});

		return $logger;
	}
}
