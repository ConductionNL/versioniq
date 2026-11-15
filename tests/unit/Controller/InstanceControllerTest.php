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

use DateTime;
use OCA\Versioniq\Controller\InstanceController;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Instance\InstanceConnectionStore;
use OCA\Versioniq\Service\Instance\InstanceConnection;
use OCA\Versioniq\Service\Instance\ManifestBuilder;
use OCA\Versioniq\Service\Instance\RemoteInstanceReader;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The connection endpoints: admin-only, password-confirmed writes, no password in any
 * response, one audit row per change (install-multi-instance-promotion task 1.2).
 *
 * @covers \OCA\Versioniq\Controller\InstanceController
 * @uses \OCA\Versioniq\Service\Instance\InstanceConnectionStore
 * @uses \OCA\Versioniq\Service\Instance\InstanceConnection
 */
final class InstanceControllerTest extends TestCase {

	/** @var array<string, string> */
	private array $config = [];

	/** @var list<array{0: AuditSubject, 1: AuditedVersions, 2: string, 3: ?string}> */
	private array $audits = [];

	private function controller(bool $admin = true, ?ManifestBuilder $manifest = null, ?RemoteInstanceReader $reader = null): InstanceController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturnCallback(fn (): array => array_keys($this->config));
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'cipher:' . md5($plain));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T08:00:00+00:00'));

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->audits[] = [$subject, $versions, $status, $message];
		});

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);

		return new InstanceController('versioniq', $this->createMock(IRequest::class), new InstanceConnectionStore($appConfig, $crypto, $time), $audit, $groups, $session, $manifest, $reader);
	}

	private function add(InstanceController $controller, string $id = 'acceptance', string $password = 'secret-app-password'): \OCP\AppFramework\Http\DataResponse {
		return $controller->create($id, 'Acceptance', 'acceptance', 1, 'https://acc.example.org', 'versioniq-reader', $password);
	}

	public function testANonAdminIsRefusedEverywhereAndNothingChanges(): void {
		$controller = $this->controller(admin: false);

		self::assertSame(403, $controller->index()->getStatus());
		self::assertSame(403, $this->add($controller)->getStatus());
		self::assertSame(403, $controller->update('acceptance', 'A', 'acceptance', 1, 'https://acc.example.org', 'u', 'p')->getStatus());
		self::assertSame(403, $controller->destroy('acceptance')->getStatus());
		self::assertSame([], $this->config);
		self::assertSame([], $this->audits);
	}

	public function testNoResponseCarriesThePassword(): void {
		$controller = $this->controller();
		$created = $this->add($controller);
		$updated = $controller->update('acceptance', 'Acceptatie', 'acceptance', 1, 'https://acc.example.org', 'versioniq-reader', 'second-app-password');
		$listed = $controller->index();

		self::assertSame(201, $created->getStatus());
		self::assertSame(200, $updated->getStatus());
		foreach ([$created, $updated, $listed] as $response) {
			$body = (string)json_encode($response->getData());
			self::assertStringNotContainsString('secret-app-password', $body);
			self::assertStringNotContainsString('second-app-password', $body);
			self::assertStringNotContainsString('cipher:', $body);
			self::assertStringNotContainsString('encryptedPassword', $body);
		}
		self::assertSame('Acceptatie', $listed->getData()['instances'][0]['name']);
		self::assertSame(['name' => '', 'stage' => '', 'order' => 0], $listed->getData()['self']);
	}

	public function testEveryChangeWritesOneAuditRow(): void {
		$controller = $this->controller();
		$this->add($controller);
		$controller->update('acceptance', 'Acceptatie', 'acceptance', 1, 'https://acc.example.org', 'versioniq-reader', '');
		$controller->destroy('acceptance');

		self::assertCount(3, $this->audits);
		foreach ($this->audits as [$subject, $versions, $status, $message]) {
			self::assertSame(['alice', 'versioniq', 'instance_connection'], [$subject->actorUid, $subject->appId, $subject->operation]);
			self::assertSame([null, null, null], [$versions->fromVersion, $versions->toVersion, $versions->sourceId]);
			self::assertSame(AuditLogger::STATUS_SUCCESS, $status);
			self::assertStringNotContainsString('secret-app-password', (string)$message);
		}
		self::assertSame(
			[
				'Connection acceptance added: Acceptance (acceptance), https://acc.example.org as versioniq-reader',
				'Connection acceptance changed: Acceptatie (acceptance), https://acc.example.org as versioniq-reader',
				'Connection acceptance removed: Acceptatie (acceptance), https://acc.example.org as versioniq-reader',
			],
			array_column($this->audits, 3)
		);
		self::assertSame([], $this->config);
	}

	public function testRefusalsAreNotAudited(): void {
		$controller = $this->controller();

		self::assertSame(400, $controller->create('acceptance', 'Acceptance', 'acceptance', 1, 'http://acc.example.org', 'u', 'p')->getStatus());
		self::assertSame(400, $this->add($controller, password: '')->getStatus());
		$this->add($controller);
		self::assertSame(409, $this->add($controller)->getStatus());
		self::assertSame(404, $controller->update('missing', 'M', 'test', 0, 'https://m.example.org', 'u', 'p')->getStatus());
		self::assertSame(404, $controller->destroy('missing')->getStatus());

		self::assertCount(1, $this->audits, 'only the one successful add');
	}

	public function testWritesNeedPasswordConfirmation(): void {
		foreach (['create', 'update', 'destroy'] as $method) {
			self::assertCount(1, (new ReflectionMethod(InstanceController::class, $method))->getAttributes(PasswordConfirmationRequired::class), $method);
		}
		self::assertCount(0, (new ReflectionMethod(InstanceController::class, 'index'))->getAttributes(PasswordConfirmationRequired::class));
	}

	public function testTheManifestIsForAdminsOnly(): void {
		$builder = $this->createMock(ManifestBuilder::class);
		$builder->expects(self::never())->method('build');

		self::assertSame(403, $this->controller(admin: false, manifest: $builder)->manifest()->getStatus());
	}

	public function testAnAdminGetsTheManifest(): void {
		$manifest = ['instanceName' => 'Test', 'stage' => 'test', 'serverVersion' => '32.0.1', 'versioniqVersion' => '1.9.0', 'apps' => []];
		$builder = $this->createMock(ManifestBuilder::class);
		$builder->expects(self::once())->method('build')->willReturn($manifest);

		$response = $this->controller(manifest: $builder)->manifest();

		self::assertSame(200, $response->getStatus());
		self::assertSame($manifest, $response->getData());
	}

	public function testWithoutABuilderTheManifestIsUnavailable(): void {
		self::assertSame(503, $this->controller()->manifest()->getStatus());
	}

	/**
	 * A reader that answers a fresh read per connection id, throws for the ids in $failing, and counts its reads.
	 *
	 * @param list<string> $failing
	 * @param array<string, int> $reads
	 */
	private function reader(array $failing, array &$reads): RemoteInstanceReader {
		$reader = $this->createMock(RemoteInstanceReader::class);
		$reader->method('fetch')->willReturnCallback(function (InstanceConnection $connection) use ($failing, &$reads): array {
			$reads[$connection->id] = ($reads[$connection->id] ?? 0) + 1;
			if (in_array($connection->id, $failing, true)) {
				throw new \RuntimeException('down');
			}
			return ['manifest' => ['apps' => [['appId' => 'openregister', 'installedVersion' => '2.4.1']]], 'fetchedAt' => 'now', 'error' => null, 'checkedAt' => 'now'];
		});
		$reader->method('cached')->willReturnCallback(static fn (InstanceConnection $connection): array => ['manifest' => ['apps' => []], 'fetchedAt' => 'yesterday', 'error' => null, 'checkedAt' => 'yesterday']);

		return $reader;
	}

	public function testRefreshIsForAdminsOnly(): void {
		$reads = [];
		self::assertSame(403, $this->controller(admin: false, reader: $this->reader([], $reads))->refresh()->getStatus());
		self::assertSame([], $reads);
	}

	public function testOneFailingConnectionDoesNotStopTheOthersOnRefresh(): void {
		$reads = [];
		$controller = $this->controller(reader: $this->reader(['acceptance'], $reads));
		$this->add($controller, 'acceptance');
		$this->add($controller, 'test');
		$reads = [];

		$response = $controller->refresh();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['acceptance' => 1, 'test' => 1], $reads);
		$byId = array_column($response->getData()['instances'], 'remote', 'id');
		self::assertSame('The instance could not be reached.', $byId['acceptance']['error']);
		self::assertSame('yesterday', $byId['acceptance']['fetchedAt']);
		self::assertSame('2.4.1', $byId['test']['manifest']['apps'][0]['installedVersion']);
		self::assertNull($byId['test']['error']);
	}

	public function testSavingAConnectionReadsItsManifestOnceAndShowsTheResult(): void {
		$reads = [];
		$controller = $this->controller(reader: $this->reader([], $reads));

		$created = $this->add($controller);

		self::assertSame(201, $created->getStatus());
		self::assertSame(['acceptance' => 1], $reads);
		self::assertSame('now', $created->getData()['remote']['fetchedAt']);
		self::assertSame('yesterday', $controller->index()->getData()['instances'][0]['remote']['fetchedAt']);
		self::assertSame(['acceptance' => 1], $reads);
	}

	public function testWithoutAReaderRefreshIsUnavailableAndListsCarryNoRemote(): void {
		$controller = $this->controller();
		$this->add($controller);

		self::assertSame(503, $controller->refresh()->getStatus());
		self::assertArrayNotHasKey('remote', $controller->index()->getData()['instances'][0]);
	}
}
