<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Instance;

use DateTime;
use OCA\Versioniq\Service\Instance\InstanceConnection;
use OCA\Versioniq\Service\Instance\InstanceConnectionStore;
use OCA\Versioniq\Service\Instance\RemoteInstanceReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading another instance's manifest: the request it sends, what a 200, 401,
 * 404 and a timeout leave in the cache, and that the password never reaches a
 * log call (install-multi-instance-promotion task 2.2); and reading its install
 * outcomes: the request, a 200, 401, 403, 404 and an unreachable instance, and
 * that one failing connection does not stop the others (releases-version-facts
 * task 2.3).
 *
 * @covers \OCA\Versioniq\Service\Instance\RemoteInstanceReader
 * @uses \OCA\Versioniq\Service\Instance\InstanceConnection
 */
final class RemoteInstanceReaderTest extends TestCase {
	private const PASSWORD = 'app-password-123';

	/** @var array<string, string> */
	private array $config = [];

	/** @var list<array{string, array<string, mixed>}> */
	private array $requests = [];

	/** @var list<array{string, array<string, mixed>}> */
	private array $logs = [];

	private string $now = '2026-11-15T08:00:00+00:00';

	private function connection(): InstanceConnection {
		return new InstanceConnection([
			'id' => 'acceptance',
			'name' => 'Acceptance',
			'stage' => 'acceptance',
			'order' => 1,
			'baseUrl' => 'https://acc.example.org/',
			'username' => 'versioniq-reader',
			'encryptedPassword' => 'encrypted',
			'addedBy' => 'alice',
			'addedAt' => '2026-11-14T08:00:00+00:00',
		]);
	}

	/**
	 * A reader whose remote answers $status with $body, or throws $error.
	 */
	private function reader(int $status = 200, string $body = '', ?\Throwable $error = null, bool $allowLocal = false): RemoteInstanceReader {
		$store = $this->createMock(InstanceConnectionStore::class);
		$store->method('withPassword')->willReturnCallback(static fn (InstanceConnection $c, callable $callback): mixed => $callback(self::PASSWORD));

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($status, $body, $error): IResponse {
			$this->requests[] = [$url, $options];
			if ($error !== null) {
				throw $error;
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn($body);
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValueBool')->with('allow_local_remote_servers', false)->willReturn($allowLocal);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime($this->now));

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []): void {
				$this->logs[] = [$message, $context];
			});
		}

		return new RemoteInstanceReader($store, $clients, $system, $appConfig, $time, $logger);
	}

	private static function manifestBody(): string {
		return (string)json_encode(['ocs' => ['meta' => ['status' => 'ok'], 'data' => [
			'instanceName' => 'acc.example.org',
			'stage' => 'acceptance',
			'apps' => [['appId' => 'openregister', 'installedVersion' => '2.4.1']],
		]]]);
	}

	public function testItSendsOneAuthenticatedOcsRequestToTheManifestEndpoint(): void {
		$this->reader(200, self::manifestBody(), allowLocal: true)->fetch($this->connection());

		self::assertCount(1, $this->requests);
		[$url, $options] = $this->requests[0];
		self::assertSame('https://acc.example.org/ocs/v2.php/apps/versioniq/api/instance/manifest', $url);
		self::assertSame(['versioniq-reader', self::PASSWORD], $options['auth']);
		self::assertSame(['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'], $options['headers']);
		self::assertSame(15, $options['timeout']);
		self::assertFalse($options['http_errors']);
		self::assertSame(['allow_local_address' => true], $options['nextcloud']);
	}

	public function testA200CachesTheManifestWithItsReadTime(): void {
		$record = $this->reader(200, self::manifestBody())->fetch($this->connection());

		self::assertSame('openregister', $record['manifest']['apps'][0]['appId']);
		self::assertSame($this->now, $record['fetchedAt']);
		self::assertNull($record['error']);
		self::assertSame($record, $this->reader()->cached($this->connection()));
		self::assertArrayHasKey('instance.acceptance.manifest', $this->config);
		self::assertStringNotContainsString(self::PASSWORD, $this->config['instance.acceptance.manifest']);
	}

	public function testA401SaysTheAccountIsNotAnAdminThereAndShowsNoData(): void {
		$record = $this->reader(401, '{"ocs":{"data":{"message":"Forbidden"}}}')->fetch($this->connection());

		self::assertSame('This account is not an admin on that instance.', $record['error']);
		self::assertNull($record['manifest']);
		self::assertNull($record['fetchedAt']);
		self::assertSame($this->now, $record['checkedAt']);
	}

	public function testA404SaysTheInstanceRunsAnOlderVersioniq(): void {
		$record = $this->reader(404)->fetch($this->connection());

		self::assertSame('This instance runs an older Versioniq without instance support.', $record['error']);
	}

	public function testAnotherStatusOrABodyWithoutAppsIsAnError(): void {
		self::assertSame('The instance answered HTTP 500.', $this->reader(500)->fetch($this->connection())['error']);
		self::assertSame('The instance did not answer with a manifest.', $this->reader(200, '{"ocs":{"data":{}}}')->fetch($this->connection())['error']);
	}

	public function testATimeoutKeepsThePreviousManifestAndLogsNoPassword(): void {
		$this->reader(200, self::manifestBody())->fetch($this->connection());
		$readAt = $this->now;
		$this->now = '2026-11-16T08:00:00+00:00';

		$record = $this->reader(error: new RuntimeException('cURL error 28: timed out for versioniq-reader:' . self::PASSWORD))->fetch($this->connection());

		self::assertSame('openregister', $record['manifest']['apps'][0]['appId']);
		self::assertSame($readAt, $record['fetchedAt']);
		self::assertSame('The instance could not be reached.', $record['error']);
		self::assertSame('2026-11-16T08:00:00+00:00', $record['checkedAt']);
		self::assertCount(1, $this->logs);
		self::assertStringNotContainsString(self::PASSWORD, (string)json_encode($this->logs));
		self::assertSame('acceptance', $this->logs[0][1]['connection']);
	}

	public function testANeverReadConnectionHasAnEmptyRecord(): void {
		self::assertSame(
			['manifest' => null, 'fetchedAt' => null, 'error' => null, 'checkedAt' => null],
			$this->reader()->cached($this->connection()),
		);
	}

	/**
	 * A reader whose remotes answer per host: [status, body], or a Throwable.
	 *
	 * @param array<string, array{int, string}|\Throwable> $answers keyed by host
	 * @param list<InstanceConnection> $connections what the store lists
	 */
	private function outcomesReader(array $answers, array $connections = []): RemoteInstanceReader {
		$store = $this->createMock(InstanceConnectionStore::class);
		$store->method('withPassword')->willReturnCallback(static fn (InstanceConnection $c, callable $callback): mixed => $callback(self::PASSWORD));
		$store->method('all')->willReturn($connections);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($answers): IResponse {
			$this->requests[] = [$url, $options];
			$answer = $answers[(string)parse_url($url, PHP_URL_HOST)];
			if ($answer instanceof \Throwable) {
				throw $answer;
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($answer[0]);
			$response->method('getBody')->willReturn($answer[1]);
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logs[] = [$message, $context];
		});

		return new RemoteInstanceReader($store, $clients, $this->createMock(IConfig::class), $this->createMock(IAppConfig::class), $this->createMock(ITimeFactory::class), $logger);
	}

	/**
	 * A connection named $name at https://{$id}.example.org.
	 */
	private static function connectionTo(string $id, string $name): InstanceConnection {
		return new InstanceConnection([
			'id' => $id,
			'name' => $name,
			'stage' => $id,
			'order' => 1,
			'baseUrl' => 'https://' . $id . '.example.org/',
			'username' => 'versioniq-reader',
			'encryptedPassword' => 'encrypted',
			'addedBy' => 'alice',
			'addedAt' => '2026-11-14T08:00:00+00:00',
		]);
	}

	/**
	 * @param array<string, mixed> $outcomes
	 */
	private static function outcomesBody(array $outcomes): string {
		return (string)json_encode(['ocs' => ['meta' => ['status' => 'ok'], 'data' => ['appId' => 'openregister', 'outcomes' => $outcomes]]]);
	}

	public function testOutcomesAreReadWithOneAuthenticatedOcsRequest(): void {
		$this->outcomesReader(['acc.example.org' => [200, self::outcomesBody([])]])->outcomes($this->connection(), 'open register');

		self::assertCount(1, $this->requests);
		[$url, $options] = $this->requests[0];
		self::assertSame('https://acc.example.org/ocs/v2.php/apps/versioniq/api/app/open%20register/outcomes', $url);
		self::assertSame(['versioniq-reader', self::PASSWORD], $options['auth']);
		self::assertSame(['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'], $options['headers']);
		self::assertSame(15, $options['timeout']);
		self::assertFalse($options['http_errors']);
	}

	public function testA200GivesTheOutcomesPerVersionAndCachesNothing(): void {
		$read = $this->outcomesReader(['acc.example.org' => [200, self::outcomesBody([
			'2.4.1' => ['succeeded' => 1, 'failed' => 1],
			'2.4.0' => ['succeeded' => 'three', 'failed' => -2],
			'2.3.0' => 'garbage',
		])]])->outcomes($this->connection(), 'openregister');

		self::assertSame([
			'id' => 'acceptance',
			'label' => 'Acceptance',
			'outcomes' => ['2.4.1' => ['succeeded' => 1, 'failed' => 1], '2.4.0' => ['succeeded' => 0, 'failed' => 0]],
			'error' => null,
		], $read);
		self::assertSame([], $this->config);
	}

	public function testOutcomesRefusedWith401Or403SayTheAccountIsNotAnAdminThere(): void {
		foreach ([401, 403] as $status) {
			$read = $this->outcomesReader(['acc.example.org' => [$status, '{"ocs":{"data":{"message":"Forbidden"}}}']])->outcomes($this->connection(), 'openregister');

			self::assertNull($read['outcomes']);
			self::assertSame('This account is not an admin on that instance.', $read['error']);
		}
	}

	public function testOutcomesAnswering404SayTheInstanceRunsAnOlderVersioniq(): void {
		$read = $this->outcomesReader(['acc.example.org' => [404, '']])->outcomes($this->connection(), 'openregister');

		self::assertNull($read['outcomes']);
		self::assertSame('This instance runs an older Versioniq without install outcomes.', $read['error']);
	}

	public function testOutcomesWithAnotherStatusOrNoOutcomesMapAreAnError(): void {
		self::assertSame('The instance answered HTTP 500.', $this->outcomesReader(['acc.example.org' => [500, '']])->outcomes($this->connection(), 'x')['error']);
		self::assertSame('The instance did not answer with install outcomes.', $this->outcomesReader(['acc.example.org' => [200, '{"ocs":{"data":{}}}']])->outcomes($this->connection(), 'x')['error']);
	}

	public function testOutcomesOfAnUnreachableInstanceAreAnErrorAndLogNoPassword(): void {
		$read = $this->outcomesReader(['acc.example.org' => new RuntimeException('cURL error 28: timed out for versioniq-reader:' . self::PASSWORD)])->outcomes($this->connection(), 'openregister');

		self::assertNull($read['outcomes']);
		self::assertSame('The instance could not be reached.', $read['error']);
		self::assertCount(1, $this->logs);
		self::assertStringNotContainsString(self::PASSWORD, (string)json_encode($this->logs));
		self::assertStringNotContainsString(self::PASSWORD, (string)json_encode($read));
		self::assertSame('acceptance', $this->logs[0][1]['connection']);
	}

	public function testOneFailingConnectionDoesNotStopTheOthersAndANamelessOneIsLabelledByItsId(): void {
		$reads = $this->outcomesReader(
			[
				'test.example.org' => new RuntimeException('down'),
				'acceptance.example.org' => [200, self::outcomesBody(['2.4.1' => ['succeeded' => 1, 'failed' => 0]])],
			],
			[self::connectionTo('test', ''), self::connectionTo('acceptance', 'Acceptance')],
		)->allOutcomes('openregister');

		self::assertCount(2, $reads);
		self::assertSame(['test', 'test', null, 'The instance could not be reached.'], [$reads[0]['id'], $reads[0]['label'], $reads[0]['outcomes'], $reads[0]['error']]);
		self::assertSame(['2.4.1' => ['succeeded' => 1, 'failed' => 0]], $reads[1]['outcomes']);
		self::assertNull($reads[1]['error']);
	}
}
