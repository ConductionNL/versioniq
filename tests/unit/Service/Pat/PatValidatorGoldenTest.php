<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Pat;

use Exception;
use OCA\Versioniq\Service\Pat\PatValidator;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for PatValidator::validate(): every forge (including an
 * unknown one and a configured Forgejo host), token kind, probe outcome,
 * scope header and expiry header. The probe request with its headers and
 * options, every logged warning and the returned ValidationResult are
 * compared, case by case as JSON text, with fixtures/pat-validate.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Service\Pat\PatValidator
 * @uses \OCA\Versioniq\Service\Pat\ValidationResult
 * @uses \OCA\Versioniq\Service\Source\Forge
 * @uses \OCA\Versioniq\Service\Source\ForgeRegistry
 */
final class PatValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/pat-validate.golden.json';

	private const BASE = [
		'token' => 'ghp_classic123',
		'forge' => 'github',
		'forgejo' => false,
		'allowLocal' => false,
		'probe' => 200,
		'headers' => ['X-OAuth-Scopes' => ['repo'], 'github-authentication-token-expiration' => ['2026-12-01 10:00:00 UTC']],
	];

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$list = ['plain' => []];
		$tokens = ['classic' => 'ghp_classic123', 'fine-grained' => 'github_pat_fine123', 'other' => 'glpat-other'];
		foreach (['github', 'codeberg', 'forgejo', 'nope', ''] as $forge) {
			foreach ($tokens as $kind => $token) {
				foreach (['throw', 401, 403, 404, 500, 200] as $probe) {
					$list["{$forge} {$kind} probe {$probe}"] = ['forge' => $forge, 'token' => $token, 'probe' => $probe, 'forgejo' => $forge === 'forgejo'];
				}
			}
		}
		$list['forgejo not configured'] = ['forge' => 'forgejo'];
		$list['allow local addresses'] = ['allowLocal' => true];

		$scopeHeaders = [
			'no headers' => [],
			'empty scopes' => ['X-OAuth-Scopes' => ['']],
			'blank scopes' => ['X-OAuth-Scopes' => ['  ,  ']],
			'public_repo' => ['X-OAuth-Scopes' => ['public_repo']],
			'repo and public_repo' => ['X-OAuth-Scopes' => ['repo, public_repo']],
			'extra scope' => ['X-OAuth-Scopes' => ['repo, read:org']],
			'two extra scopes' => ['X-OAuth-Scopes' => ['admin:org,repo,delete_repo']],
			'string header' => ['X-OAuth-Scopes' => 'repo, gist'],
			'lowercase header' => ['x-oauth-scopes' => ['gist']],
			'empty header list' => ['X-OAuth-Scopes' => []],
			'non-string header value' => ['X-OAuth-Scopes' => [5]],
			'numeric header' => ['X-OAuth-Scopes' => 7],
			'other header first' => ['X-Other' => ['x'], 'X-OAuth-Scopes' => ['repo']],
		];
		$expiry = [
			'expiry missing' => [],
			'expiry blank' => ['github-authentication-token-expiration' => [' ']],
			'expiry invalid' => ['github-authentication-token-expiration' => ['not a date']],
			'expiry string' => ['github-authentication-token-expiration' => '2027-01-02T03:04:05+02:00'],
			'expiry uppercase key' => ['GitHub-Authentication-Token-Expiration' => ['2027-05-06 07:08:09 UTC']],
		];
		foreach ($tokens as $kind => $token) {
			foreach ($scopeHeaders as $name => $headers) {
				$list["{$kind}, {$name}"] = ['token' => $token, 'headers' => $headers + ['github-authentication-token-expiration' => ['2026-12-01 10:00:00 UTC']]];
			}
			foreach ($expiry as $name => $headers) {
				$list["{$kind}, {$name}"] = ['token' => $token, 'headers' => ['X-OAuth-Scopes' => ['repo']] + $headers];
			}
		}

		return $list;
	}

	public function testEveryScenarioMatchesTheGolden(): void {
		$actual = [];
		foreach (self::scenarios() as $name => $over) {
			$actual[$name] = $this->runScenario(array_replace(self::BASE, $over));
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			if (!is_dir(dirname(self::FIXTURE))) {
				mkdir(dirname(self::FIXTURE));
			}
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->markTestSkipped('Golden fixture recorded.');
		}

		/** @var array<string, mixed> $expected */
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertSame(array_keys($expected), array_keys($actual), 'scenario list changed');
		foreach ($actual as $name => $case) {
			self::assertSame(
				json_encode($expected[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				"scenario {$name}",
			);
		}
	}

	/**
	 * @param array<string, mixed> $s
	 * @return array<string, mixed>
	 */
	private function runScenario(array $s): array {
		$this->calls = [];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $s['forgejo'] === true && $key === 'forge.forgejo.web_base' ? 'https://git.example.org/' : $default
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(function (string $key, bool $default) use ($s): bool {
			$this->calls[] = ['config.bool', $key, $default];

			return (bool)$s['allowLocal'];
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['logger.warning', $message, $context];
		});

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($s): IResponse {
			$this->calls[] = ['http.get', $url, $options];
			if ($s['probe'] === 'throw') {
				throw new Exception('Connection refused.');
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn((int)$s['probe']);
			$response->method('getHeaders')->willReturn((array)$s['headers']);

			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturnCallback(function () use ($client): IClient {
			$this->calls[] = ['http.newClient'];

			return $client;
		});

		$validator = new PatValidator($clientService, $logger, new ForgeRegistry($appConfig), $config);
		$result = $validator->validate((string)$s['token'], (string)$s['forge']);

		return ['result' => get_object_vars($result), 'calls' => $this->calls];
	}
}
