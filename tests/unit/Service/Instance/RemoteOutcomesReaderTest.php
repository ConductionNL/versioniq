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

use OCA\Versioniq\Service\Instance\InstanceConnection;
use OCA\Versioniq\Service\Instance\InstanceConnectionStore;
use OCA\Versioniq\Service\Instance\RemoteOutcomesReader;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading another instance's install outcomes: the request it sends, what a
 * 200, 401, 403, 404 and an unreachable instance give, that one failing
 * connection does not stop the others, and that the password never reaches a
 * log call (releases-version-facts task 2.3).
 *
 * @covers \OCA\Versioniq\Service\Instance\RemoteOutcomesReader
 * @uses \OCA\Versioniq\Service\Instance\InstanceConnection
 */
final class RemoteOutcomesReaderTest extends TestCase {
	private const PASSWORD = 'app-password-123';

	/** @var list<array{string, array<string, mixed>}> */
	private array $requests = [];

	/** @var list<array{string, array<string, mixed>}> */
	private array $logs = [];

	private static function connection(string $id = 'acceptance', string $name = 'Acceptance'): InstanceConnection {
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
	 * A reader whose remotes answer per host: [status, body] or a Throwable.
	 *
	 * @param array<string, array{int, string}|\Throwable> $answers keyed by host
	 * @param list<InstanceConnection> $connections
	 */
	private function reader(array $answers, array $connections = [], bool $allowLocal = false): RemoteOutcomesReader {
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

		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValueBool')->with('allow_local_remote_servers', false)->willReturn($allowLocal);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []): void {
				$this->logs[] = [$message, $context];
			});
		}

		return new RemoteOutcomesReader($store, $clients, $system, $logger);
	}

	/**
	 * @param array<string, mixed> $outcomes
	 */
	private static function body(array $outcomes): string {
		return (string)json_encode(['ocs' => ['meta' => ['status' => 'ok'], 'data' => ['appId' => 'openregister', 'outcomes' => $outcomes]]]);
	}

	public function testItSendsOneAuthenticatedOcsRequestToTheOutcomesEndpoint(): void {
		$this->reader(['acceptance.example.org' => [200, self::body([])]], allowLocal: true)->read(self::connection(), 'open register');

		self::assertCount(1, $this->requests);
		[$url, $options] = $this->requests[0];
		self::assertSame('https://acceptance.example.org/ocs/v2.php/apps/versioniq/api/app/open%20register/outcomes', $url);
		self::assertSame(['versioniq-reader', self::PASSWORD], $options['auth']);
		self::assertSame(['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'], $options['headers']);
		self::assertSame(15, $options['timeout']);
		self::assertFalse($options['http_errors']);
		self::assertSame(['allow_local_address' => true], $options['nextcloud']);
	}

	public function testA200GivesTheCountsPerVersion(): void {
		$read = $this->reader(['acceptance.example.org' => [200, self::body([
			'2.4.1' => ['succeeded' => 1, 'failed' => 1],
			'2.4.0' => ['succeeded' => 'three', 'failed' => -2],
			'2.3.0' => 'garbage',
		])]])->read(self::connection(), 'openregister');

		self::assertSame([
			'id' => 'acceptance',
			'label' => 'Acceptance',
			'outcomes' => ['2.4.1' => ['succeeded' => 1, 'failed' => 1], '2.4.0' => ['succeeded' => 0, 'failed' => 0]],
			'error' => null,
		], $read);
	}

	public function testA401Or403SaysTheAccountIsNotAnAdminThere(): void {
		foreach ([401, 403] as $status) {
			$read = $this->reader(['acceptance.example.org' => [$status, '{"ocs":{"data":{"message":"Forbidden"}}}']])->read(self::connection(), 'openregister');

			self::assertNull($read['outcomes']);
			self::assertSame('This account is not an admin on that instance.', $read['error']);
		}
	}

	public function testA404SaysTheInstanceRunsAnOlderVersioniq(): void {
		$read = $this->reader(['acceptance.example.org' => [404, '']])->read(self::connection(), 'openregister');

		self::assertNull($read['outcomes']);
		self::assertSame('This instance runs an older Versioniq without install outcomes.', $read['error']);
	}

	public function testAnotherStatusOrABodyWithoutOutcomesIsAnError(): void {
		self::assertSame('The instance answered HTTP 500.', $this->reader(['acceptance.example.org' => [500, '']])->read(self::connection(), 'x')['error']);
		self::assertSame('The instance did not answer with install outcomes.', $this->reader(['acceptance.example.org' => [200, '{"ocs":{"data":{}}}']])->read(self::connection(), 'x')['error']);
	}

	public function testAnUnreachableInstanceIsAnErrorAndLogsNoPassword(): void {
		$read = $this->reader(['acceptance.example.org' => new RuntimeException('cURL error 28: timed out for versioniq-reader:' . self::PASSWORD)])->read(self::connection(), 'openregister');

		self::assertNull($read['outcomes']);
		self::assertSame('The instance could not be reached.', $read['error']);
		self::assertCount(1, $this->logs);
		self::assertStringNotContainsString(self::PASSWORD, (string)json_encode($this->logs));
		self::assertStringNotContainsString(self::PASSWORD, (string)json_encode($read));
		self::assertSame('acceptance', $this->logs[0][1]['connection']);
	}

	public function testOneFailingConnectionDoesNotStopTheOthersAndANamelessOneIsLabelledByItsId(): void {
		$reads = $this->reader(
			[
				'test.example.org' => new RuntimeException('down'),
				'acceptance.example.org' => [200, self::body(['2.4.1' => ['succeeded' => 1, 'failed' => 0]])],
			],
			[self::connection('test', ''), self::connection()],
		)->readAll('openregister');

		self::assertCount(2, $reads);
		self::assertSame(['test', 'test', null, 'The instance could not be reached.'], [$reads[0]['id'], $reads[0]['label'], $reads[0]['outcomes'], $reads[0]['error']]);
		self::assertSame(['2.4.1' => ['succeeded' => 1, 'failed' => 0]], $reads[1]['outcomes']);
		self::assertNull($reads[1]['error']);
	}
}
