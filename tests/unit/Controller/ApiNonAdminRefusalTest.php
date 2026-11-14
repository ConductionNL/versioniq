<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit test for the admin guard of every ApiController endpoint: a signed-in
 * non-admin and an anonymous caller both get 403 {"message": "Forbidden"},
 * and the request never reaches the installer, stores, PAT mapper, cache or
 * discovery collaborators.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @uses \OCA\Versioniq\Http\InstallVersionFields
 * @uses \OCA\Versioniq\Http\RequestFields
 * @uses \OCA\Versioniq\Service\Api\AccessCollaborators
 * @uses \OCA\Versioniq\Service\Api\ApiCaller
 * @uses \OCA\Versioniq\Service\Api\InstallCollaborators
 * @uses \OCA\Versioniq\Service\Api\PatServices
 * @uses \OCA\Versioniq\Service\Api\ReportCollaborators
 * @uses \OCA\Versioniq\Service\Api\ReportStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryFilter
 */
final class ApiNonAdminRefusalTest extends TestCase {
	use BuildsByParameterName;

	/**
	 * @return array<string, array{0: string, 1: list<mixed>}>
	 */
	public static function endpoints(): array {
		return [
			'apps' => ['apps', []],
			'advisories' => ['advisories', []],
			'updates' => ['updates', []],
			'updateChannel' => ['updateChannel', []],
			'sources' => ['sources', []],
			'getBinding' => ['getBinding', ['mail']],
			'clearBinding' => ['clearBinding', ['mail']],
			'bindSource' => ['bindSource', ['mail']],
			'addTrustedSource' => ['addTrustedSource', ['repo']],
			'removeTrustedSource' => ['removeTrustedSource', []],
			'appVersions' => ['appVersions', ['mail', 'github']],
			'installVersion' => ['installVersion', ['mail', '4.0.0']],
			'pins' => ['pins', []],
			'pinApp' => ['pinApp', ['mail']],
			'unpinApp' => ['unpinApp', ['mail']],
			'retryAttempt' => ['retryAttempt', ['mail', '4.0.0']],
			'policies' => ['policies', []],
			'setPolicy' => ['setPolicy', ['mail']],
			'clearPolicy' => ['clearPolicy', ['mail']],
			'skippedVersions' => ['skippedVersions', ['mail']],
			'skipVersion' => ['skipVersion', ['mail', '4.0.1']],
			'unskipVersion' => ['unskipVersion', ['mail', '4.0.1']],
			'updateAutoUpdateSettings' => ['updateAutoUpdateSettings', ['1']],
			'advisorySettings' => ['advisorySettings', []],
			'updateAdvisorySettings' => ['updateAdvisorySettings', ['24', '1']],
			'listPats' => ['listPats', []],
			'createPat' => ['createPat', []],
			'patchPat' => ['patchPat', [7, 'label', '1']],
			'deletePat' => ['deletePat', [7]],
			'discover' => ['discover', ['1']],
			'patDeeplink' => ['patDeeplink', []],
			'auditLog' => ['auditLog', []],
			'cacheSummary' => ['cacheSummary', []],
			'clearCache' => ['clearCache', []],
		];
	}

	/**
	 * A collaborator double that fails the test on any call.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function untouched(string $class): object {
		$mock = $this->createMock($class);
		$mock->expects($this->never())->method($this->anything());

		return $mock;
	}

	/**
	 * A request whose parameters are all absent: some endpoints read their
	 * fields before the guard, which must still refuse.
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);

		return $request;
	}

	private function controller(?IUser $user, bool $isAdmin): ApiController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);
		if ($user === null) {
			$groups->expects($this->never())->method('isAdmin');
		}

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $this->request(),
			'userSession' => $session,
			'groupManager' => $groups,
			'serverVersion' => (new ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
			'installerService' => $this->untouched(InstallerService::class),
			'artifactCache' => $this->untouched(ArtifactCache::class),
			'patMapper' => $this->untouched(PatMapper::class),
			'patManager' => $this->untouched(PatManager::class),
			'deeplinkBuilder' => $this->untouched(PatDeeplinkBuilder::class),
			'discoveryAggregator' => $this->untouched(DiscoveryAggregator::class),
			'pinStore' => $this->untouched(PinStore::class),
			'policyStore' => $this->untouched(PolicyStore::class),
			'appManager' => $this->untouched(IAppManager::class),
			'auditEntryMapper' => $this->untouched(AuditEntryMapper::class),
			'connectionReports' => null,
		]);
	}

	/**
	 * @dataProvider endpoints
	 * @param list<mixed> $args
	 */
	public function testASignedInNonAdminIsRefused(string $method, array $args): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');

		$response = $this->controller($user, false)->{$method}(...$args);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Forbidden'], $response->getData());
	}

	/**
	 * @dataProvider endpoints
	 * @param list<mixed> $args
	 */
	public function testAnAnonymousCallerIsRefused(string $method, array $args): void {
		$response = $this->controller(null, false)->{$method}(...$args);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Forbidden'], $response->getData());
	}
}
