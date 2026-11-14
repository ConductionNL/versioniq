<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Checks;

use Exception;
use OCA\Versioniq\Service\Checks\CheckRunner;
use OCA\Versioniq\Service\Checks\CheckStore;
use OCA\Versioniq\Service\Checks\CommandCheck;
use OCA\Versioniq\Service\Checks\HttpVerdictCheck;
use OCA\Versioniq\Service\Checks\UpdateCheck;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Verdict checks answer with a status, command checks with an exit code; both
 * are reported per check, a long command check is skipped in a web request,
 * and command arguments never pass through a shell.
 *
 * @covers \OCA\Versioniq\Service\Checks\CheckRunner
 * @covers \OCA\Versioniq\Service\Checks\HttpVerdictCheck
 * @covers \OCA\Versioniq\Service\Checks\CommandCheck
 * @covers \OCA\Versioniq\Service\Checks\CheckSubject
 * @uses \OCA\Versioniq\Service\Checks\UpdateCheck
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
final class CheckRunnerTest extends TestCase {
	/** @var list<array{url: string, options: array<string, mixed>}> */
	private array $requests = [];

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/versioniq-checks-' . bin2hex(random_bytes(4));
		mkdir($this->dir . '/someapp/appinfo', 0777, true);
	}

	protected function tearDown(): void {
		foreach (['/someapp/appinfo', '/someapp', ''] as $sub) {
			foreach ((array)glob($this->dir . $sub . '/*') as $file) {
				if (is_file((string)$file)) {
					unlink((string)$file);
				}
			}
		}
		@rmdir($this->dir . '/someapp/appinfo');
		@rmdir($this->dir . '/someapp');
		@rmdir($this->dir);
		parent::tearDown();
	}

	/**
	 * @param list<UpdateCheck> $checks
	 * @param int|\Throwable $answer The status the verdict address answers, or what the client throws.
	 */
	private function runner(array $checks, int|\Throwable $answer = 200, bool $allowLocal = false): CheckRunner {
		$store = $this->createMock(CheckStore::class);
		$store->method('all')->willReturn($checks);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($answer): IResponse {
			$this->requests[] = ['url' => $url, 'options' => $options];
			if ($answer instanceof \Throwable) {
				throw $answer;
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($answer);

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn($allowLocal);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(static fn (string $cipher): string => str_replace('enc:', '', $cipher));

		return new CheckRunner($store, new HttpVerdictCheck($clients, $config, $crypto), new CommandCheck());
	}

	private static function staging(string $url = 'https://ci.example.org/verdict/{appId}/{version}', ?string $token = null, array $apps = []): UpdateCheck {
		return UpdateCheck::verdict('abc', 'Staging tests', $url, $apps, $token);
	}

	/**
	 * A command check running this PHP binary with `$code`.
	 *
	 * @param list<string> $arguments
	 */
	private static function php(string $name, string $code, array $arguments = [], int $timeout = 30): UpdateCheck {
		return UpdateCheck::command($name, [PHP_BINARY, '-n', '-r', $code, '--', ...$arguments], [], $timeout);
	}

	/**
	 * @return array{name: string, kind: string, outcome: string, passed: bool, detail: string}
	 */
	private function runOne(CheckRunner $runner, string $context = CheckRunner::CONTEXT_CLI, string $version = '2.4.0'): array {
		$results = $runner->run('openregister', '2.3.0', $version, $this->dir . '/someapp', $context);
		self::assertCount(1, $results);

		return $results[0];
	}

	public function testA2xxVerdictPasses(): void {
		$result = $this->runOne($this->runner([self::staging()], 204));

		self::assertSame(['name' => 'Staging tests', 'kind' => 'verdict', 'outcome' => 'passed', 'passed' => true, 'detail' => 'HTTP 204'], $result);
		self::assertSame('https://ci.example.org/verdict/openregister/2.4.0', $this->requests[0]['url']);
		self::assertSame(15, $this->requests[0]['options']['timeout']);
		self::assertFalse($this->requests[0]['options']['allow_redirects']);
		self::assertFalse($this->requests[0]['options']['http_errors']);
		self::assertSame(['allow_local_address' => false], $this->requests[0]['options']['nextcloud']);
	}

	public function testA404VerdictFailsWithItsStatus(): void {
		$result = $this->runOne($this->runner([self::staging()], 404));

		self::assertSame('failed', $result['outcome']);
		self::assertFalse($result['passed']);
		self::assertSame('HTTP 404', $result['detail']);
		self::assertSame(['name' => 'Staging tests', 'detail' => 'HTTP 404'], CheckRunner::firstFailure([$result]));
	}

	public function testARedirectIsNotAPass(): void {
		self::assertSame('failed', $this->runOne($this->runner([self::staging()], 302))['outcome']);
	}

	public function testAVerdictThatTimesOutFails(): void {
		$result = $this->runOne($this->runner([self::staging()], new Exception('cURL error 28: Operation timed out after 15001 milliseconds')));

		self::assertSame('failed', $result['outcome']);
		self::assertSame('No answer: cURL error 28: Operation timed out after 15001 milliseconds', $result['detail']);
	}

	public function testPlaceholdersAreUrlEncoded(): void {
		$runner = $this->runner([self::staging('https://ci.example.org/verdict/{appId}/{version}?from={fromVersion}&x=1')]);

		$runner->run('openregister', '2.3.0 beta', '2.4.0+build/1&evil=1', $this->dir, CheckRunner::CONTEXT_WEB);

		self::assertSame('https://ci.example.org/verdict/openregister/2.4.0%2Bbuild%2F1%26evil%3D1?from=2.3.0%20beta&x=1', $this->requests[0]['url']);
	}

	public function testTheTokenGoesOnlyOverHttps(): void {
		$this->runOne($this->runner([self::staging(token: 'enc:t0ken')]));
		self::assertSame('Bearer t0ken', $this->requests[0]['options']['headers']['Authorization']);

		// An http address set through occ is called only where local servers are allowed, and never with the token.
		$http = self::staging('http://forge.test:8080/verdict/{appId}/{version}', 'enc:t0ken');
		$refused = $this->runOne($this->runner([$http]));
		self::assertSame('failed', $refused['outcome']);
		self::assertCount(1, $this->requests, 'an http address was called');

		$this->runOne($this->runner([$http], 200, true));
		self::assertArrayNotHasKey('Authorization', $this->requests[1]['options']['headers']);
		self::assertSame(['allow_local_address' => true], $this->requests[1]['options']['nextcloud']);
	}

	public function testOnlyChecksForTheAppRun(): void {
		$runner = $this->runner([self::staging(apps: ['openconnector']), self::php('Everywhere', 'exit(0);')]);

		self::assertTrue($runner->matches('openregister'));
		self::assertSame(['Everywhere'], array_column($runner->run('openregister', '2.3.0', '2.4.0', $this->dir), 'name'));
		self::assertSame([], $this->requests);
		self::assertFalse($this->runner([self::staging(apps: ['openconnector'])])->matches('openregister'));
	}

	public function testACommandThatExitsZeroPassesAndOneFails(): void {
		$passed = $this->runOne($this->runner([self::php('Lint', 'echo "all good"; exit(0);')]));
		self::assertSame('passed', $passed['outcome']);
		self::assertSame('command', $passed['kind']);
		self::assertSame("Exit code 0.\nall good", $passed['detail']);

		$failed = $this->runOne($this->runner([self::php('Lint', 'fwrite(STDERR, "lint error"); exit(1);')]));
		self::assertSame('failed', $failed['outcome']);
		self::assertSame("Exit code 1.\nlint error", $failed['detail']);
	}

	public function testArgumentsReachTheProgramAsTextWithoutAShell(): void {
		$marker = $this->dir . '/injected';
		$code = 'echo json_encode(array_slice($argv, 1));';
		$check = self::php('Args', $code, ['{appId}', "x; touch {$marker}", '$(touch ' . $marker . ')', '{path}', '{version}-{fromVersion}']);

		$result = $this->runOne($this->runner([$check]));

		self::assertSame('passed', $result['outcome']);
		$argv = json_decode(substr($result['detail'], strlen("Exit code 0.\n")), true);
		self::assertSame(['openregister', "x; touch {$marker}", '$(touch ' . $marker . ')', $this->dir . '/someapp', '2.4.0-2.3.0'], $argv);
		self::assertFileDoesNotExist($marker, 'a shell interpreted an argument');
	}

	public function testTheCommandRunsInTheUnpackedPackage(): void {
		file_put_contents($this->dir . '/someapp/appinfo/info.xml', '<info/>');

		$result = $this->runOne($this->runner([self::php('Info', 'exit(is_file("appinfo/info.xml") ? 0 : 3);')]));

		self::assertSame('passed', $result['outcome']);
	}

	public function testAnOverlongCommandIsSkippedInAWebRequestOnly(): void {
		$marker = $this->dir . '/ran';
		$check = self::php('Slow suite', 'touch($argv[1]);', [$marker], 120);

		$skipped = $this->runOne($this->runner([$check]), CheckRunner::CONTEXT_WEB);
		self::assertSame('skipped', $skipped['outcome']);
		self::assertFalse($skipped['passed']);
		self::assertSame('Runs from the command line and the nightly job only.', $skipped['detail']);
		self::assertFileDoesNotExist($marker);
		self::assertNull(CheckRunner::firstFailure([$skipped]), 'a skipped check is no failure');

		self::assertSame('passed', $this->runOne($this->runner([$check]), CheckRunner::CONTEXT_CLI)['outcome']);
		self::assertFileExists($marker);

		// At most 60 seconds runs in a web request too.
		self::assertSame('passed', $this->runOne($this->runner([self::php('Quick', 'exit(0);', [], 60)]), CheckRunner::CONTEXT_WEB)['outcome']);
	}

	public function testOnlyTheLast2KiBOfOutputAreKept(): void {
		$result = $this->runOne($this->runner([self::php('Noisy', 'echo str_repeat("a", 100000), "Bearer abc.def\nEND"; exit(1);')]));

		$output = substr($result['detail'], strlen("Exit code 1.\n"));
		self::assertStringStartsWith("Exit code 1.\n", $result['detail']);
		self::assertLessThanOrEqual(CommandCheck::OUTPUT_TAIL_BYTES, strlen($output));
		self::assertStringEndsWith("Bearer [redacted]\nEND", $output);
		self::assertStringNotContainsString('abc.def', $output);
	}

	public function testACommandThatRunsTooLongIsKilledAndFails(): void {
		$started = microtime(true);

		$result = $this->runOne($this->runner([self::php('Hangs', 'echo "started"; sleep(30);', [], 1)]));

		self::assertLessThan(10, microtime(true) - $started);
		self::assertSame('failed', $result['outcome']);
		self::assertSame("Timed out after 1 s.\nstarted", $result['detail']);
	}

	public function testAProgramThatDoesNotExistFails(): void {
		$result = $this->runOne($this->runner([UpdateCheck::command('Missing', [$this->dir . '/no-such-program'], [], 5)]));

		self::assertSame('failed', $result['outcome']);
	}

	public function testChecksRunInOrderAndEachIsReported(): void {
		$results = $this->runner([self::staging(), self::php('Lint', 'exit(2);')], 200)->run('openregister', '2.3.0', '2.4.0', $this->dir, CheckRunner::CONTEXT_CLI);

		self::assertSame([['Staging tests', 'passed'], ['Lint', 'failed']], array_map(static fn (array $result): array => [$result['name'], $result['outcome']], $results));
		self::assertSame('Lint', CheckRunner::firstFailure($results)['name'] ?? null);
	}
}
