<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use DateTime;
use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit test for the ApiController branches the endpoint tests leave open:
 * the route version as the install target, a pin the installed version
 * cannot form, an owner's PAT update, and the audit page bounds.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @uses \OCA\Versioniq\Db\Pat
 * @uses \OCA\Versioniq\Service\Api\AccessCollaborators
 * @uses \OCA\Versioniq\Service\Api\ApiCaller
 * @uses \OCA\Versioniq\Service\Api\InstallCollaborators
 * @uses \OCA\Versioniq\Service\Api\PatServices
 * @uses \OCA\Versioniq\Service\Api\ReportCollaborators
 * @uses \OCA\Versioniq\Service\Api\ReportStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 * @uses \OCA\Versioniq\Service\Installer\InstallOptions
 * @uses \OCA\Versioniq\Service\Installer\InstallRequest
 * @uses \OCA\Versioniq\Service\Installer\InstallTarget
 * @uses \OCA\Versioniq\Service\Installer\PinChoice
 * @uses \OCA\Versioniq\Service\Pin\Pin
 */
final class ApiControllerBranchesTest extends TestCase {
	use BuildsByParameterName;

	/**
	 * An admin controller over the given collaborators and request params.
	 *
	 * @param array<string, mixed> $params
	 * @param array<string, mixed> $collaborators
	 */
	private function controller(array $params = [], array $collaborators = []): ApiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $params) ? $params[$key] : $default,
		);
		$request->method('getParams')->willReturn($params);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('admin')->willReturn(true);

		return $this->buildByName(ApiController::class, $collaborators + [
			'appName' => 'versioniq',
			'request' => $request,
			'userSession' => $session,
			'groupManager' => $groups,
			'serverVersion' => (new ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
			'connectionReports' => null,
		]);
	}

	/**
	 * Without a `targetVersion` or `version` parameter the route's version is installed.
	 */
	public function testTheRouteVersionIsInstalledWhenTheBodyNamesNone(): void {
		$requests = [];
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('installAppVersion')->willReturnCallback(
			static function (InstallRequest $request) use (&$requests): array {
				$requests[] = $request;

				return ['statusCode' => 200, 'payload' => ['installStatus' => 'installed']];
			},
		);

		$response = $this->controller([], ['installerService' => $installer])->installVersion('notes', '2.0.0');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'installStatus' => 'installed',
			'requestedVersion' => '2.0.0',
			'routeVersion' => '2.0.0',
		], $response->getData());
		$this->assertSame('notes', $requests[0]->target->appId);
		$this->assertSame('2.0.0', $requests[0]->target->version);
		$this->assertNull($requests[0]->target->sourceOverride);
	}

	/**
	 * A `version` body parameter wins over the route version when no `targetVersion` is given.
	 */
	public function testAVersionParameterWinsOverTheRouteVersion(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('installAppVersion')
			->with($this->callback(static fn (InstallRequest $request): bool => $request->target->version === '2.1.0'))
			->willReturn(['statusCode' => 200, 'payload' => []]);

		$response = $this->controller(['version' => '2.1.0'], ['installerService' => $installer])->installVersion('notes', '2.0.0');

		$this->assertSame(['requestedVersion' => '2.1.0', 'routeVersion' => '2.0.0'], $response->getData());
	}

	/**
	 * An installed version a pin cannot hold is a 400 with the pin's own message, and nothing is stored.
	 */
	public function testAnInstalledVersionAPinCannotHoldIsABadRequest(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->with('mail', false)->willReturn('1.0 beta');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:00:00+00:00'));
		$pins = $this->createMock(PinStore::class);
		$pins->expects($this->never())->method('set');

		$response = $this->controller([], ['appManager' => $apps, 'timeFactory' => $time, 'pinStore' => $pins])->pinApp('mail');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Pin version is empty or contains invalid characters'], $response->getData());
	}

	/**
	 * The owner's PAT update.
	 *
	 * @param string|null $label    The label sent.
	 * @param string|null $shared   The sharedWithAdmins flag sent.
	 * @param string      $newLabel The label stored.
	 * @param bool        $nowShared Whether the PAT is shared afterwards.
	 */
	#[DataProvider('patches')]
	public function testTheOwnerUpdatesOnlyTheFieldsSent(?string $label, ?string $shared, string $newLabel, bool $nowShared): void {
		$pat = new Pat();
		$pat->setId(9);
		$pat->setOwnerUid('admin');
		$pat->setLabel('old label');
		$pat->setSharedWithAdmins(true);
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findById')->with(9)->willReturn($pat);
		$manager = $this->createMock(PatManager::class);
		$manager->expects($this->once())->method('update')->with($this->identicalTo($pat))->willReturnArgument(0);
		$expiry = $this->createMock(PatExpiryEvaluator::class);
		$expiry->method('evaluate')->with(null)->willReturn(['state' => 'none', 'daysRemaining' => null]);

		$response = $this->controller([], ['patMapper' => $mapper, 'patManager' => $manager, 'patExpiryEvaluator' => $expiry])
			->patchPat(9, $label, $shared);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData()['pat'];
		$this->assertSame($newLabel, $data['label']);
		$this->assertSame($nowShared, $data['sharedWithAdmins']);
		$this->assertSame('none', $data['expiryState']);
		$this->assertNull($data['daysRemaining']);
		$this->assertSame($newLabel, $pat->getLabel());
		$this->assertSame($nowShared, $pat->getSharedWithAdmins());
	}

	/**
	 * @return array<string, array{0: string|null, 1: string|null, 2: string, 3: bool}>
	 */
	public static function patches(): array {
		return [
			'label trimmed and unshared' => ['  new label ', '0', 'new label', false],
			'nothing sent' => [null, null, 'old label', true],
			'blank label keeps the old one' => ['   ', 'false', 'old label', false],
			'unknown flag keeps the current value' => [null, 'maybe', 'old label', true],
			'true flag' => ['x', 'TRUE', 'x', true],
		];
	}

	/**
	 * Limit and offset are read from integers or digit strings, bounded, and fall back to the defaults.
	 *
	 * @param array<string, mixed> $params
	 */
	#[DataProvider('auditPages')]
	public function testTheAuditPageBounds(array $params, ?string $appId, int $limit, int $offset): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->once())->method('findPage')->with($appId, $limit, $offset)->willReturn([]);

		$response = $this->controller($params, ['auditEntryMapper' => $mapper])->auditLog();

		$this->assertSame(['entries' => [], 'limit' => $limit, 'offset' => $offset], $response->getData());
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: int, 3: int}>
	 */
	public static function auditPages(): array {
		return [
			'defaults' => [[], null, 50, 0],
			'integers' => [['limit' => 20, 'offset' => 40, 'appId' => 'mail'], 'mail', 20, 40],
			'digit strings with spaces' => [['limit' => ' 30 ', 'offset' => '10'], null, 30, 10],
			'non-numeric falls back' => [['limit' => 'ten', 'offset' => '1.5'], null, 50, 0],
			'blank falls back' => [['limit' => '  '], null, 50, 0],
			'limit capped at 200' => [['limit' => 5000], null, 200, 0],
			'limit at least 1, offset not negative' => [['limit' => '-3', 'offset' => -7], null, 1, 0],
		];
	}
}
