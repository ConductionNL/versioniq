<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Connection;

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * ConnectionReportService tells integriq what Versioniq met on its three
 * outside connections. Every test guards one way it could quietly stop telling
 * the truth: a report sent before the refresh that retires it, a rate limit
 * read as configured, a repository path on a row, a report per page request,
 * a listener's failure turned into a failed save, or an event sent when
 * integriq is not installed.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
 */
final class ConnectionReportServiceTest extends TestCase {
	private IEventDispatcher&MockObject $dispatcher;
	private IAppConfig&MockObject $appConfig;
	private ITimeFactory&MockObject $time;
	private LoggerInterface&MockObject $logger;

	/** @var list<Event> Every event handed to the dispatcher, in order. */
	private array $sent = [];

	/** @var array<string, string> The app config the service reads and writes. */
	private array $config = [];

	private int $now = 1_800_000_000;

	protected function setUp(): void {
		$this->sent = [];
		$this->config = [];
		$this->now = 1_800_000_000;

		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->sent[] = $event;
		});

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$app . '/' . $key] ?? $default,
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$app . '/' . $key] = $value;

				return true;
			},
		);
		$this->appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$app . '/' . $key]);
		});

		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(): ConnectionReportService {
		return new ConnectionReportService($this->dispatcher, $this->appConfig, $this->time, $this->logger);
	}

	/**
	 * The service as it behaves on an instance without integriq. Only the class
	 * lookup is replaced: the stubs make both classes resolvable in this process.
	 */
	private function serviceWithoutIntegriq(): ConnectionReportService {
		return new class($this->dispatcher, $this->appConfig, $this->time, $this->logger) extends ConnectionReportService {
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}
		};
	}

	/**
	 * @return list<string> Everything sent, as "refresh:key" or "report:key:status".
	 */
	private function sentSummary(): array {
		return array_map(static function (Event $event): string {
			if ($event instanceof ConnectionStatusReportedEvent) {
				return 'report:' . $event->key . ':' . $event->status;
			}
			if ($event instanceof ConnectionRefreshRequestedEvent) {
				return 'refresh:' . (string)$event->key;
			}

			return get_class($event);
		}, $this->sent);
	}

	public function testATokenSaveRefreshesBeforeItReports(): void {
		// Under hydra#674 a refresh retires every older observation, so the
		// other order would have integriq throw the report away.
		self::assertTrue($this->service()->forgeTokenSaved('github'));

		self::assertSame(['refresh:github', 'report:github:configured'], $this->sentSummary());
		self::assertSame('versioniq', $this->sent[0]->app);
		self::assertSame('versioniq', $this->sent[1]->app);
		self::assertSame('The token check reached GitHub, and GitHub accepted the token.', $this->sent[1]->message);
	}

	public function testATokenRemovalRefreshesAndReportsNothing(): void {
		self::assertTrue($this->service()->forgeTokenRemoved('github'));

		self::assertSame(['refresh:github'], $this->sentSummary());
	}

	public function testCodebergIsNeverReported(): void {
		$service = $this->service();

		self::assertFalse($service->forgeTokenSaved('codeberg'));
		self::assertFalse($service->forgeTokenRemoved('codeberg'));
		self::assertFalse($service->forgeAnswered('codeberg', 'https://codeberg.org/api/v1', 500));

		self::assertSame([], $this->sentSummary());
		self::assertSame([], $this->config);
	}

	public function testEveryGithubAnswerMapsToTheDesignedStatus(): void {
		$service = $this->service();
		$api = 'https://api.github.com';

		self::assertSame(['configured', 'api.github.com answered the last request.'], $service->describeForgeAnswer($api, 200));
		self::assertSame(['error', 'api.github.com answered with a body Versioniq could not read.'], $service->describeForgeAnswer($api, 200, false));
		self::assertSame('limited', $service->describeForgeAnswer($api, 401)[0] ?? null);
		self::assertSame(
			['limited', 'api.github.com refused the last request with HTTP 403, usually a rate limit. A token raises the limit.'],
			$service->describeForgeAnswer($api, 403),
		);
		self::assertSame('limited', $service->describeForgeAnswer($api, 429)[0] ?? null);
		self::assertSame(['error', 'api.github.com answered HTTP 502.'], $service->describeForgeAnswer($api, 502));
		self::assertSame(['error', 'Versioniq could not reach api.github.com.'], $service->describeForgeAnswer($api, null));
	}

	public function testAMissingRepositoryIsNotAConnectionFailure(): void {
		self::assertNull($this->service()->describeForgeAnswer('https://api.github.com', 404));
		self::assertFalse($this->service()->forgeAnswered('github', 'https://api.github.com', 404));
		self::assertSame([], $this->sentSummary());
	}

	public function testAMessageNamesTheConfiguredHostAndNothingElse(): void {
		$message = $this->service()->describeForgeAnswer('https://user:s3cret@ghe.gemeente.example/api/v3?token=abc', 502)[1] ?? '';

		self::assertSame('ghe.gemeente.example answered HTTP 502.', $message);
		foreach (['s3cret', 'user', 'token', 'abc', '/api'] as $leak) {
			self::assertStringNotContainsString($leak, $message);
		}
	}

	public function testTheSameStatusIsReportedOnceAnHour(): void {
		$service = $this->service();

		self::assertTrue($service->forgeAnswered('github', 'https://api.github.com', 200));
		$this->now += 600;
		self::assertFalse($service->forgeAnswered('github', 'https://api.github.com', 200));
		self::assertSame(['report:github:configured'], $this->sentSummary());

		// A change goes out at once.
		self::assertTrue($service->forgeAnswered('github', 'https://api.github.com', 503));
		self::assertSame(['report:github:configured', 'report:github:error'], $this->sentSummary());

		// The same status again, an hour later.
		$this->now += ConnectionReportService::THROTTLE_SECONDS;
		self::assertTrue($service->forgeAnswered('github', 'https://api.github.com', 503));
		self::assertCount(3, $this->sent);
	}

	public function testARefreshClearsTheThrottle(): void {
		$service = $this->service();

		self::assertTrue($service->forgeAnswered('github', 'https://api.github.com', 200));
		self::assertTrue($service->forgeTokenRemoved('github'));
		$this->now += 5;

		// The refresh retired the earlier report, so the same status must go
		// out again or the row would read "Not checked yet" for an hour.
		self::assertTrue($service->forgeAnswered('github', 'https://api.github.com', 200));
		self::assertSame(['report:github:configured', 'refresh:github', 'report:github:configured'], $this->sentSummary());
	}

	public function testTheThrottleIsKeptPerConnection(): void {
		$service = $this->service();

		self::assertTrue($service->forgeAnswered('github', 'https://api.github.com', 200));
		self::assertTrue($service->appStoreFetched(true, ''));
		self::assertTrue($service->advisoryFeedRead(12, null));

		self::assertSame(
			['report:github:configured', 'report:appstore:configured', 'report:advisories:configured'],
			$this->sentSummary(),
		);
		self::assertSame('configured|' . $this->now, $this->config['versioniq/connection_report.appstore']);
	}

	public function testAnAppStoreFetchMapsItsOutcome(): void {
		$service = $this->service();

		self::assertSame(['configured', 'The App Store answered the last catalogue request.'], $service->describeAppStoreFetch(true, 'HTTP 502'));
		self::assertSame(['error', 'The App Store did not answer the last catalogue request: HTTP 502'], $service->describeAppStoreFetch(false, 'HTTP 502'));
		self::assertSame(['error', 'The App Store did not answer the last catalogue request.'], $service->describeAppStoreFetch(false, ''));

		$long = $service->describeAppStoreFetch(false, str_repeat('x', 400))[1];
		self::assertStringContainsString(str_repeat('x', ConnectionReportService::REASON_LIMIT) . '...', $long);
		self::assertStringNotContainsString(str_repeat('x', ConnectionReportService::REASON_LIMIT + 1), $long);
	}

	public function testAnAdvisoryCheckMapsItsOutcome(): void {
		$service = $this->service();

		self::assertSame(['configured', 'The last check read 277 advisories from the feed.'], $service->describeAdvisoryRead(277, null));
		self::assertSame(
			['limited', 'The last check stopped after 100 advisories. The Nextcloud advisory feed returned HTTP 502.'],
			$service->describeAdvisoryRead(100, 'The Nextcloud advisory feed returned HTTP 502.'),
		);
		self::assertSame(
			['error', 'The Nextcloud advisory feed returned HTTP 403.'],
			$service->describeAdvisoryRead(0, 'The Nextcloud advisory feed returned HTTP 403.'),
		);
	}

	public function testAFailureReasonNamesAHostAndNeverAPath(): void {
		$service = $this->service();
		$reason = 'Could not read the Nextcloud advisory feed: cURL error 28 for https://token:s3cret@mirror.example/repos/nextcloud/security-advisories?per_page=100&after=abc';

		foreach ([$service->describeAdvisoryRead(0, $reason)[1], $service->describeAppStoreFetch(false, $reason)[1]] as $message) {
			self::assertStringContainsString('for mirror.example', $message);
			foreach (['s3cret', 'token', '/repos', 'nextcloud/security-advisories', 'after=abc'] as $leak) {
				self::assertStringNotContainsString($leak, $message);
			}
		}
	}

	public function testWithoutIntegriqNothingIsSentStoredOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->appConfig->expects($this->never())->method('getValueString');
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->never())->method('warning');

		$service = $this->serviceWithoutIntegriq();

		self::assertFalse($service->forgeTokenSaved('github'));
		self::assertFalse($service->forgeTokenRemoved('github'));
		self::assertFalse($service->forgeAnswered('github', 'https://api.github.com', 200));
		self::assertFalse($service->appStoreFetched(false, 'HTTP 502'));
		self::assertFalse($service->advisoryFeedRead(0, 'down'));
	}

	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		// The real guard, not the double above.
		$method = new ReflectionMethod(ConnectionReportService::class, 'resolveEventClass');
		$service = $this->service();

		self::assertNull($method->invoke($service, 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		self::assertSame(ConnectionReportService::STATUS_EVENT, $method->invoke($service, ConnectionReportService::STATUS_EVENT));
	}

	public function testTheEventNamesAreTheContractNames(): void {
		// A string class name is exactly the reference that rots into a silent
		// no-op after a rename, so it is compared to the stubs' real names.
		self::assertSame(ConnectionStatusReportedEvent::class, ConnectionReportService::STATUS_EVENT);
		self::assertSame(ConnectionRefreshRequestedEvent::class, ConnectionReportService::REFRESH_EVENT);
	}

	public function testAThrowingListenerNeverEscapesAndIsNotRemembered(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->exactly(2))->method('warning')
			->with($this->stringContains('could not send'), $this->arrayHasKey('key'));

		$service = new ConnectionReportService($dispatcher, $this->appConfig, $this->time, $this->logger);

		self::assertFalse($service->forgeAnswered('github', 'https://api.github.com', 200));
		self::assertFalse($service->forgeTokenSaved('github'));
		// Nothing reached integriq, so nothing may suppress the next attempt.
		self::assertSame([], $this->config);
	}
}
