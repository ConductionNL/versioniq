<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use Exception;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatResolver;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A GitHub release request hands what GitHub answered to the connection
 * report, and a Codeberg request hands over nothing. The report service is the
 * real one, so this covers the wiring and the mapping together.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
 */
final class ForgeReleaseSourceConnectionReportTest extends TestCase {
	/** @var list<Event> */
	private array $sent = [];

	private function source(IClient $client): ForgeReleaseSource {
		$this->sent = [];

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$patResolver = $this->createMock(PatResolver::class);
		$patResolver->method('findFor')->willReturn(null);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->sent[] = $event;
		});
		// No earlier report, so nothing is throttled.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);

		$reports = new ConnectionReportService($dispatcher, $appConfig, $time, $this->createMock(LoggerInterface::class));

		return new ForgeReleaseSource(
			$clientService,
			$this->createMock(LoggerInterface::class),
			$patResolver,
			$this->createMock(PatManager::class),
			$userSession,
			new ForgeRegistry($this->createMock(IAppConfig::class)),
			$this->createMock(IConfig::class),
			$reports,
		);
	}

	private function answering(int $status, string $body): IClient {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		return $client;
	}

	/**
	 * @return list<string> Each report as "key:status:message".
	 */
	private function reports(): array {
		return array_values(array_map(
			static fn (ConnectionStatusReportedEvent $event): string => $event->key . ':' . $event->status . ':' . $event->message,
			array_filter($this->sent, static fn (Event $event): bool => $event instanceof ConnectionStatusReportedEvent),
		));
	}

	public function testASuccessfulListingReportsConfigured(): void {
		$source = $this->source($this->answering(200, json_encode([['tag_name' => 'v1.0.0']], JSON_THROW_ON_ERROR)));

		$result = $source->listVersions('openregister', SourceBinding::github('ConductionNL', 'openregister'));

		self::assertNull($result['error']);
		self::assertSame(['github:configured:api.github.com answered the last request.'], $this->reports());
	}

	public function testARateLimitReportsLimitedWithoutTheRepository(): void {
		$source = $this->source($this->answering(403, '{}'));

		$result = $source->listVersions('openregister', SourceBinding::github('ConductionNL', 'openregister'));

		// The listing answers exactly as before.
		self::assertSame([], $result['versions']);
		self::assertStringContainsString('rate limit', (string)$result['error']);

		$reports = $this->reports();
		self::assertCount(1, $reports);
		self::assertStringStartsWith('github:limited:api.github.com refused the last request with HTTP 403', $reports[0]);
		self::assertStringNotContainsString('ConductionNL', $reports[0]);
		self::assertStringNotContainsString('openregister', $reports[0]);
	}

	public function testNoAnswerReportsError(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new Exception('cURL error 6: Could not resolve host'));

		$this->source($client)->listVersions('openregister', SourceBinding::github('ConductionNL', 'openregister'));

		self::assertSame(['github:error:Versioniq could not reach api.github.com.'], $this->reports());
	}

	public function testAnUnreadableBodyReportsError(): void {
		$this->source($this->answering(200, 'not json'))
			->listVersions('openregister', SourceBinding::github('ConductionNL', 'openregister'));

		self::assertSame(['github:error:api.github.com answered with a body Versioniq could not read.'], $this->reports());
	}

	public function testAMissingRepositoryReportsNothing(): void {
		$this->source($this->answering(404, '{}'))
			->listVersions('openregister', SourceBinding::github('ConductionNL', 'gone'));

		self::assertSame([], $this->reports());
	}

	public function testACodebergRequestReportsNothing(): void {
		$this->source($this->answering(500, '{}'))
			->listVersions('openregister', SourceBinding::codeberg('ConductionNL', 'openregister'));

		self::assertSame([], $this->sent);
	}
}
