<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatValidator;
use OCA\Versioniq\Service\Pat\ValidationResult;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Saving or removing a token asks integriq to look at the GitHub connection
 * again, in the order hydra#674 needs, and never changes the response. The
 * report service is the real one behind a recording dispatcher.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
 */
final class ApiConnectionReportTest extends TestCase {
	/** @var list<Event> */
	private array $sent = [];

	/**
	 * @param array<string, string> $params
	 */
	private function controller(array $params, PatValidator $validator, PatManager $patManager, PatMapper $patMapper, ?IEventDispatcher $dispatcher = null): ApiController {
		$this->sent = [];

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default,
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		if ($dispatcher === null) {
			$dispatcher = $this->createMock(IEventDispatcher::class);
			$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
				$this->sent[] = $event;
			});
		}
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);
		$reports = new ConnectionReportService($dispatcher, $appConfig, $time, $this->createMock(LoggerInterface::class));

		$expiry = $this->createMock(PatExpiryEvaluator::class);
		$expiry->method('evaluate')->willReturn(['state' => 'ok', 'daysRemaining' => null]);

		return new ApiController(
			'versioniq',
			$request,
			$this->createMock(InstallerService::class),
			$groupManager,
			$session,
			(new ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
			$patMapper,
			$patManager,
			$validator,
			$this->createMock(PatDeeplinkBuilder::class),
			$expiry,
			$this->createMock(DiscoveryAggregator::class),
			$this->createMock(AdvisoryResultStore::class),
			$this->createMock(AdvisorySettingsStore::class),
			$this->createMock(AuditEntryMapper::class),
			$this->createMock(PinStore::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(PolicyStore::class),
			$this->createMock(AutoUpdateSettingsStore::class),
			$this->createMock(ArtifactCache::class),
			$reports,
		);
	}

	private function pat(string $forge): Pat {
		$pat = new Pat();
		$pat->setId(7);
		$pat->setOwnerUid('admin');
		$pat->setLabel('releases');
		$pat->setKind(Pat::KIND_FINE_GRAINED);
		$pat->setForge($forge);
		$pat->setTargetPattern('ConductionNL/*');

		return $pat;
	}

	private function validator(ValidationResult $result): PatValidator {
		$validator = $this->createMock(PatValidator::class);
		$validator->method('validate')->willReturn($result);
		$validator->method('detectKind')->willReturn(Pat::KIND_FINE_GRAINED);

		return $validator;
	}

	/**
	 * @return list<string>
	 */
	private function summary(): array {
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

	/**
	 * @return array<string, string>
	 */
	private function tokenParams(string $forge): array {
		return ['label' => 'releases', 'targetPattern' => 'ConductionNL/*', 'token' => 'github_pat_x', 'forge' => $forge];
	}

	public function testSavingAGithubTokenRefreshesThenReports(): void {
		$patManager = $this->createMock(PatManager::class);
		$patManager->expects($this->once())->method('create')->willReturn($this->pat('github'));

		$response = $this->controller($this->tokenParams('github'), $this->validator(ValidationResult::accepted([], [], null)), $patManager, $this->createMock(PatMapper::class))
			->createPat();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['refresh:github', 'report:github:configured'], $this->summary());
	}

	public function testARejectedTokenSendsNothing(): void {
		$patManager = $this->createMock(PatManager::class);
		$patManager->expects($this->never())->method('create');

		$response = $this->controller($this->tokenParams('github'), $this->validator(ValidationResult::rejected('Token is invalid or revoked.')), $patManager, $this->createMock(PatMapper::class))
			->createPat();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([], $this->summary());
	}

	public function testSavingACodebergTokenSendsNothing(): void {
		$patManager = $this->createMock(PatManager::class);
		$patManager->method('create')->willReturn($this->pat('codeberg'));

		$this->controller($this->tokenParams('codeberg'), $this->validator(ValidationResult::accepted([], [], null)), $patManager, $this->createMock(PatMapper::class))
			->createPat();

		self::assertSame([], $this->summary());
	}

	public function testRemovingAGithubTokenRefreshesOnly(): void {
		$patMapper = $this->createMock(PatMapper::class);
		$patMapper->method('findById')->willReturn($this->pat('github'));
		$patManager = $this->createMock(PatManager::class);
		$patManager->expects($this->once())->method('delete');

		$response = $this->controller([], $this->validator(ValidationResult::accepted([], [], null)), $patManager, $patMapper)
			->deletePat(7);

		self::assertSame(['deleted' => 7], $response->getData());
		self::assertSame(['refresh:github'], $this->summary());
	}

	public function testAFailingRegistryNeverChangesTheSaveResponse(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$patManager = $this->createMock(PatManager::class);
		$patManager->method('create')->willReturn($this->pat('github'));

		$response = $this->controller($this->tokenParams('github'), $this->validator(ValidationResult::accepted([], [], null)), $patManager, $this->createMock(PatMapper::class), $dispatcher)
			->createPat();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertArrayHasKey('pat', $response->getData());
	}
}
