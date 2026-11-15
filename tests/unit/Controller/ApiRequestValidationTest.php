<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use InvalidArgumentException;
use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit test for the admin-side request handling of ApiController that the
 * endpoint tests leave open: source and binding reads, trusted-pattern
 * validation, version listing status passthrough, discovery query bounds,
 * PAT deeplinks, cache endpoints, missing PATs and apps whose installed
 * version cannot be read.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @uses \OCA\Versioniq\Service\Api\AccessCollaborators
 * @uses \OCA\Versioniq\Service\Api\ApiCaller
 * @uses \OCA\Versioniq\Service\Api\InstallCollaborators
 * @uses \OCA\Versioniq\Service\Api\PatServices
 * @uses \OCA\Versioniq\Service\Api\ReportCollaborators
 * @uses \OCA\Versioniq\Service\Api\ReportStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryFilter
 * @uses \OCA\Versioniq\Service\Pin\Pin
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 */
final class ApiRequestValidationTest extends TestCase {
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

	public function testSourcesListsTheRegistryAndTheTrustedPatterns(): void {
		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('listAvailable')->willReturn([['id' => 'appstore'], ['id' => 'github']]);
		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->method('getPatterns')->willReturn(['github:conduction/*']);
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getSourceRegistry')->willReturn($registry);
		$installer->method('getTrustedSources')->willReturn($trusted);

		$response = $this->controller([], ['installerService' => $installer])->sources();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'sources' => [['id' => 'appstore'], ['id' => 'github']],
			'trustedPatterns' => ['github:conduction/*'],
		], $response->getData());
	}

	public function testGetBindingOfABoundApp(): void {
		$binding = SourceBinding::github('conduction', 'mail');
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('getBinding')->with('mail')->willReturn($binding);

		$data = $this->controller([], ['installerService' => $installer])->getBinding('mail')->getData();

		$this->assertSame('mail', $data['appId']);
		$this->assertSame($binding->toArray(), $data['binding']);
		$this->assertSame($binding->getId(), $data['sourceId']);
		$this->assertNotSame('appstore', $data['sourceId']);
	}

	public function testGetBindingOfAnUnboundAppFallsBackToTheAppStore(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getBinding')->willReturn(null);

		$response = $this->controller([], ['installerService' => $installer])->getBinding('deck');

		$this->assertSame(['appId' => 'deck', 'binding' => null, 'sourceId' => 'appstore'], $response->getData());
	}

	public function testRemovingABlankPatternIsABadRequest(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->never())->method('removeTrustedPattern');

		$response = $this->controller(['pattern' => '   '], ['installerService' => $installer])->removeTrustedSource();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'A pattern is required.'], $response->getData());
	}

	public function testRemovingAPatternTrimsIt(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('removeTrustedPattern')->with('github:evil/*')->willReturn(['github:conduction/*']);

		$response = $this->controller(['pattern' => ' github:evil/* '], ['installerService' => $installer])->removeTrustedSource();

		$this->assertSame(['trustedPatterns' => ['github:conduction/*']], $response->getData());
	}

	public function testAnInvalidTrustedPatternIsUnprocessable(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('addTrustedPattern')->with('github', 'conduction', null)
			->willThrowException(new InvalidArgumentException('That pattern is too broad.'));

		$response = $this->controller(['forge' => 'github', 'owner' => 'conduction'], ['installerService' => $installer])
			->addTrustedSource('  ');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'That pattern is too broad.'], $response->getData());
	}

	public function testAppVersionsTrimsTheSourceAndPassesTheStatusThrough(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('getAppVersions')->with('mail', 'github')
			->willReturn(['message' => 'Not found', 'statusCode' => 404, 'hasError' => true]);

		$response = $this->controller([], ['installerService' => $installer])->appVersions('mail', ' github ');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}

	public function testAppVersionsWithABlankSourceAndAnUnknownStatusFallsBackTo200(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('getAppVersions')->with('mail', null)
			->willReturn(['versions' => ['4.0.0'], 'statusCode' => 299]);

		$response = $this->controller([], ['installerService' => $installer])->appVersions('mail', '  ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['versions' => ['4.0.0']], $response->getData());
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function badQueries(): array {
		return [
			'empty' => ['', 'Query must be at least 2 characters.'],
			'one character' => ['a', 'Query must be at least 2 characters.'],
			'one multibyte character' => ['é', 'Query must be at least 2 characters.'],
			'101 characters' => [str_repeat('x', 101), 'Query must be at most 100 characters.'],
		];
	}

	/**
	 * @dataProvider badQueries
	 */
	public function testDiscoverRefusesQueriesOutsideTheBounds(string $query, string $message): void {
		$aggregator = $this->createMock(DiscoveryAggregator::class);
		$aggregator->expects($this->never())->method('search');

		$response = $this->controller(['q' => $query], ['discoveryAggregator' => $aggregator])->discover();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => $message], $response->getData());
	}

	public function testANonGithubForgeGetsTheForgeTokenDeeplink(): void {
		$builder = $this->createMock(PatDeeplinkBuilder::class);
		$builder->expects($this->once())->method('build')->with('forge-token', 'forgejo')->willReturn(['url' => 'https://forge/token']);

		$response = $this->controller(['forge' => 'forgejo', 'kind' => 'classic'], ['deeplinkBuilder' => $builder])->patDeeplink();

		$this->assertSame(['url' => 'https://forge/token'], $response->getData());
	}

	public function testAnUnknownPatKindIsABadRequest(): void {
		$builder = $this->createMock(PatDeeplinkBuilder::class);
		$builder->expects($this->once())->method('build')->with('weird', 'github')
			->willThrowException(new InvalidArgumentException('Unknown PAT kind: weird'));

		$response = $this->controller(['kind' => 'weird'], ['deeplinkBuilder' => $builder])->patDeeplink();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Unknown PAT kind: weird'], $response->getData());
	}

	public function testCacheSummaryReturnsTheCacheSummary(): void {
		$cache = $this->createMock(ArtifactCache::class);
		$cache->expects($this->never())->method('clear');
		$cache->method('summary')->willReturn(['apps' => [], 'totalBytes' => 0]);

		$response = $this->controller([], ['artifactCache' => $cache])->cacheSummary();

		$this->assertSame(['apps' => [], 'totalBytes' => 0], $response->getData());
	}

	public function testClearCacheForOneAppOrForAll(): void {
		$cleared = [];
		$cache = $this->createMock(ArtifactCache::class);
		$cache->method('clear')->willReturnCallback(function (?string $appId) use (&$cleared): void {
			$cleared[] = $appId;
		});
		$cache->method('summary')->willReturn(['totalBytes' => 0]);

		$this->controller(['appId' => ' mail '], ['artifactCache' => $cache])->clearCache();
		$response = $this->controller([], ['artifactCache' => $cache])->clearCache();

		$this->assertSame(['mail', null], $cleared);
		$this->assertSame(['totalBytes' => 0], $response->getData());
	}

	public function testPatchingOrDeletingAMissingPatIsNotFound(): void {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findById')->with(42)->willThrowException(new DoesNotExistException('gone'));

		$patch = $this->controller([], ['patMapper' => $mapper])->patchPat(42, 'new label', '1');
		$delete = $this->controller([], ['patMapper' => $mapper])->deletePat(42);

		foreach ([$patch, $delete] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['message' => 'PAT not found.'], $response->getData());
		}
	}

	public function testAPinWhoseAppVersionCannotBeReadListsNoInstalledVersion(): void {
		$pins = $this->createMock(PinStore::class);
		$pins->method('all')->willReturn(['gone' => new Pin('1.2.3', 'admin', '2026-11-01T00:00:00+00:00')]);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->with('gone', false)->willThrowException(new AppPathNotFoundException('gone'));

		$response = $this->controller([], ['pinStore' => $pins, 'appManager' => $apps])->pins();

		$this->assertSame(['pins' => [[
			'version' => '1.2.3',
			'pinnedBy' => 'admin',
			'pinnedAt' => '2026-11-01T00:00:00+00:00',
			'appId' => 'gone',
			'installedVersion' => null,
		]]], $response->getData());
	}

	public function testPinningAnAppWhoseVersionCannotBeReadIsABadRequest(): void {
		$pins = $this->createMock(PinStore::class);
		$pins->expects($this->never())->method($this->logicalNot($this->equalTo('all')));
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willThrowException(new AppPathNotFoundException('mail'));

		$response = $this->controller(['version' => '4.0.0'], ['pinStore' => $pins, 'appManager' => $apps])->pinApp('mail');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'App is not installed.'], $response->getData());
	}
}
