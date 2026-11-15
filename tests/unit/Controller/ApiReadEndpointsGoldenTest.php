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

require_once __DIR__ . '/../Support/BuildsByParameterName.php';
require_once __DIR__ . '/../Service/Installer/InstallerGoldenSupport.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\Discovery\DiscoveryFilter;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Tests\Unit\Service\Installer\InstallerGoldenSupport;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Golden over the API endpoints that hand a collaborator's answer straight
 * back, or pass the request straight on: each case records the response and
 * every collaborator call, compared with fixtures/api-read-endpoints.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite the fixture.
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
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryFilter
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 * @uses \OCA\Versioniq\Service\Pin\Pin
 * @uses \OCA\Versioniq\Service\Policy\Policy
 */
final class ApiReadEndpointsGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/api-read-endpoints.golden.json';

	/** @var list<array<array-key, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private array $params = [];

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function caseNames(): array {
		$names = [];
		foreach (['apps', 'advisories', 'advisory interval', 'advisory interval out of range', 'pats', 'discover', 'discover installed only', 'deeplink github', 'deeplink gitea', 'clear one cache', 'clear every cache', 'pin', 'set policy', 'update channel'] as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}

	/**
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		$this->calls = [];
		$response = $this->call($name, $this->controller());
		$actual = json_encode(['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}

	private function call(string $name, ApiController $controller): DataResponse {
		return match ($name) {
			'apps' => $controller->apps(),
			'advisories' => $controller->advisories(),
			'advisory interval' => $controller->updateAdvisorySettings('12'),
			'advisory interval out of range' => $controller->updateAdvisorySettings('99'),
			'pats' => $controller->listPats(),
			'discover' => $this->withParams(['q' => 'calendar', 'sources' => 'appstore, github'], $controller)->discover(),
			'discover installed only' => $this->withParams(['q' => 'mail'], $controller)->discover('1'),
			'deeplink github' => $this->withParams(['kind' => Pat::KIND_CLASSIC], $controller)->patDeeplink(),
			'deeplink gitea' => $this->withParams(['forge' => 'gitea'], $controller)->patDeeplink(),
			'clear one cache' => $this->withParams(['appId' => 'notes'], $controller)->clearCache(),
			'clear every cache' => $controller->clearCache(),
			'pin' => $this->withParams(['reason' => 'waiting for 5.0'], $controller)->pinApp('notes'),
			'set policy' => $this->withParams(['level' => 'patch'], $controller)->setPolicy('notes'),
			'update channel' => $this->withUpdaterChannel('beta', static fn (): DataResponse => $controller->updateChannel()),
		};
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function withParams(array $params, ApiController $controller): ApiController {
		$this->params = $params;

		return $controller;
	}

	/**
	 * Runs $call while the server configuration names $channel as the
	 * updater channel, the value ServerVersion::getChannel() reads.
	 *
	 * @param callable(): DataResponse $call
	 */
	private function withUpdaterChannel(string $channel, callable $call): DataResponse {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('A real server types OC::$server, so the container cannot be replaced.');
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($channel);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($config);
		$previous = \OC::$server;
		\OC::$server = $container;
		try {
			return $call();
		} finally {
			\OC::$server = $previous;
		}
	}

	/**
	 * A ServerVersion whose own channel is $channel, without reading the
	 * server's version.php.
	 */
	private function serverVersion(string $channel): ServerVersion {
		$version = (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor();
		(function (string $channel): void {
			$this->channel = $channel;
		})->call($version, $channel);

		return $version;
	}

	private function controller(): ApiController {
		$this->params = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([['id' => 'notes', 'version' => '4.1.0']]);

		$advisoryResults = $this->createMock(AdvisoryResultStore::class);
		$advisoryResults->method('read')->willReturn(['advisories' => ['notes' => ['state' => 'advisory-available']], 'checkedAt' => 1_700_000_000]);

		$advisorySettings = $this->createMock(AdvisorySettingsStore::class);
		$advisorySettings->method('setIntervalHours')->willReturnCallback(function (int $hours): void {
			$this->calls[] = ['advisorySettings.setIntervalHours', $hours];
		});
		$advisorySettings->method('getIntervalHours')->willReturn(6);
		$advisorySettings->method('isDigestEnabled')->willReturn(true);

		$pat = new Pat();
		$pat->setOwnerUid('admin');
		$pat->setLabel('releases');
		$pat->setForge('github');
		$pat->setExpiresAt('2027-01-01T00:00:00Z');
		$patMapper = $this->createMock(PatMapper::class);
		$patMapper->method('findVisibleTo')->willReturnCallback(function (string $uid) use ($pat): array {
			$this->calls[] = ['patMapper.findVisibleTo', $uid];

			return [$pat];
		});
		$expiry = $this->createMock(PatExpiryEvaluator::class);
		$expiry->method('evaluate')->willReturnCallback(function (?string $expiresAt): array {
			$this->calls[] = ['patExpiry.evaluate', $expiresAt];

			return ['state' => 'ok', 'daysRemaining' => 42];
		});

		$discovery = $this->createMock(DiscoveryAggregator::class);
		$discovery->method('search')->willReturnCallback(function (string $query, DiscoveryFilter $filter): array {
			$this->calls[] = ['discovery.search', $query, get_object_vars($filter)];

			return ['hits' => [['appId' => 'calendar']], 'errors' => []];
		});

		$deeplinks = $this->createMock(PatDeeplinkBuilder::class);
		$deeplinks->method('build')->willReturnCallback(function (string $kind, string $forge): array {
			$this->calls[] = ['deeplink.build', $kind, $forge];

			return ['url' => 'https://' . $forge . '.example/token?kind=' . $kind];
		});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('4.1.0');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-02T10:30:00+00:00'));
		$pins = $this->createMock(PinStore::class);
		$pins->method('set')->willReturnCallback(function (string $appId, Pin $pin): void {
			$this->calls[] = ['pins.set', $appId, $pin->toArray()];
		});
		$policies = $this->createMock(PolicyStore::class);
		$policies->method('set')->willReturnCallback(function (string $appId, Policy $policy): void {
			$this->calls[] = ['policies.set', $appId, $policy->toArray()];
		});

		$cache = $this->createMock(ArtifactCache::class);
		$cache->method('clear')->willReturnCallback(function (?string $appId): void {
			$this->calls[] = ['cache.clear', $appId];
		});
		$cache->method('summary')->willReturn(['entries' => 3, 'bytes' => 1024]);

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $request,
			'userSession' => $session,
			'groupManager' => $groupManager,
			'installerService' => $installer,
			'advisoryResultStore' => $advisoryResults,
			'advisorySettingsStore' => $advisorySettings,
			'patMapper' => $patMapper,
			'patExpiryEvaluator' => $expiry,
			'discoveryAggregator' => $discovery,
			'deeplinkBuilder' => $deeplinks,
			'artifactCache' => $cache,
			'appManager' => $appManager,
			'timeFactory' => $time,
			'pinStore' => $pins,
			'policyStore' => $policies,
			'serverVersion' => $this->serverVersion('stable'),
		]);
	}
}
