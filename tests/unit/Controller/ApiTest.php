<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatValidator;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ApiTest extends TestCase {
	private function fakeServerVersion(): ServerVersion {
		// ServerVersion is `readonly` and cannot be mocked; bypass the
		// constructor to get an inert instance for tests that don't touch it.
		return (new ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor();
	}

	private function fakeTimeFactory(): ITimeFactory {
		$factory = $this->createMock(ITimeFactory::class);
		$factory->method('getDateTime')->willReturn(new \DateTime('2026-07-24T00:00:00+00:00'));

		return $factory;
	}

	private function buildController(
		?IRequest $request = null,
		?AuditEntryMapper $auditEntryMapper = null,
		?PinStore $pinStore = null,
		?IAppManager $appManager = null,
		?PolicyStore $policyStore = null,
		?AutoUpdateSettingsStore $autoUpdateSettingsStore = null,
		?ArtifactCache $artifactCache = null,
	): ApiController {
		return new ApiController(
			'versioniq',
			$request ?? $this->createMock(IRequest::class),
			$this->createMock(InstallerService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserSession::class),
			$this->fakeServerVersion(),
			$this->createMock(PatMapper::class),
			$this->createMock(PatManager::class),
			$this->createMock(PatValidator::class),
			$this->createMock(PatDeeplinkBuilder::class),
			$this->createMock(PatExpiryEvaluator::class),
			$this->createMock(DiscoveryAggregator::class),
			$this->createMock(AdvisoryResultStore::class),
			$this->createMock(AdvisorySettingsStore::class),
			$auditEntryMapper ?? $this->createMock(AuditEntryMapper::class),
			$pinStore ?? $this->createMock(PinStore::class),
			$appManager ?? $this->createMock(IAppManager::class),
			$this->fakeTimeFactory(),
			$policyStore ?? $this->createMock(PolicyStore::class),
			$autoUpdateSettingsStore ?? $this->createMock(AutoUpdateSettingsStore::class),
			$artifactCache ?? $this->createMock(ArtifactCache::class),
		);
	}

	/**
	 * Builds a controller whose isAdmin() returns true, with the given installer
	 * service and request wired in.
	 */
	private function buildAdminController(
		InstallerService $installer,
		IRequest $request,
		?AuditEntryMapper $auditEntryMapper = null,
		?PinStore $pinStore = null,
		?IAppManager $appManager = null,
		string $uid = 'admin',
		?PolicyStore $policyStore = null,
		?AutoUpdateSettingsStore $autoUpdateSettingsStore = null,
		?ArtifactCache $artifactCache = null,
	): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with($uid)->willReturn(true);

		return new ApiController(
			'versioniq',
			$request,
			$installer,
			$groupManager,
			$session,
			$this->fakeServerVersion(),
			$this->createMock(PatMapper::class),
			$this->createMock(PatManager::class),
			$this->createMock(PatValidator::class),
			$this->createMock(PatDeeplinkBuilder::class),
			$this->createMock(PatExpiryEvaluator::class),
			$this->createMock(DiscoveryAggregator::class),
			$this->createMock(AdvisoryResultStore::class),
			$this->createMock(AdvisorySettingsStore::class),
			$auditEntryMapper ?? $this->createMock(AuditEntryMapper::class),
			$pinStore ?? $this->createMock(PinStore::class),
			$appManager ?? $this->createMock(IAppManager::class),
			$this->fakeTimeFactory(),
			$policyStore ?? $this->createMock(PolicyStore::class),
			$autoUpdateSettingsStore ?? $this->createMock(AutoUpdateSettingsStore::class),
			$artifactCache ?? $this->createMock(ArtifactCache::class),
		);
	}

	public function testReadBinaryBoolAcceptsCommonTruthyAndFalsyValues(): void {
		$controller = $this->buildController();

		$method = new ReflectionMethod(ApiController::class, 'readBinaryBool');
		$invoke = static fn (mixed $value, bool $default): bool => (bool)$method->invoke($controller, $value, $default);

		$this->assertTrue($invoke('1', false));
		$this->assertTrue($invoke('true', false));
		$this->assertTrue($invoke(true, false));
		$this->assertTrue($invoke(1, false));
		$this->assertFalse($invoke('0', true));
		$this->assertFalse($invoke('false', true));
		$this->assertFalse($invoke(false, true));
		$this->assertFalse($invoke(0, true));
		$this->assertSame(true, $invoke('garbage', true));
		$this->assertSame(false, $invoke('garbage', false));
	}

	public function testStringParamTrimsAndFallsBack(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'present' => '  hello  ',
				'array' => ['unexpected'],
				default => $default,
			}
		);

		$controller = $this->buildController($request);

		$method = new ReflectionMethod(ApiController::class, 'stringParam');

		$this->assertSame('hello', $method->invoke($controller, 'present', 'default'));
		$this->assertSame('default', $method->invoke($controller, 'missing', 'default'));
		$this->assertSame('default', $method->invoke($controller, 'array', 'default'));
	}

	public function testInstallerServiceClassExists(): void {
		// Smoke test that InstallerService autoloads cleanly from the new namespace structure.
		$this->assertTrue(class_exists(InstallerService::class));
	}

	public function testAddTrustedSourceForbiddenForNonAdmin(): void {
		// The default mocked IGroupManager/IUserSession make isAdmin() false.
		$response = $this->buildController()->addTrustedSource();

		$this->assertSame(403, $response->getStatus());
	}

	public function testRemoveTrustedSourceForbiddenForNonAdmin(): void {
		$response = $this->buildController()->removeTrustedSource();

		$this->assertSame(403, $response->getStatus());
	}

	public function testAddTrustedSourceAsAdminReturnsPatterns(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				// `repo` is a declared parameter now, so it is NOT mocked here —
				// this test exercises the omitted case, which the parameter's
				// own null default provides. A leftover 'repo' => null entry
				// would look like it were feeding the method and feed nothing.
				'forge' => 'github',
				'owner' => 'ConductionNL',
				default => $default,
			}
		);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('addTrustedPattern')
			->with('github', 'ConductionNL', null)
			->willReturn(['github:ConductionNL/*']);

		$response = $this->buildAdminController($installer, $request)->addTrustedSource();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['trustedPatterns' => ['github:ConductionNL/*']], $response->getData());
	}

	public function testRemoveTrustedSourceAsAdminReturnsRemainingPatterns(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'pattern' => 'github:ConductionNL/*',
				default => $default,
			}
		);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('removeTrustedPattern')
			->with('github:ConductionNL/*')
			->willReturn([]);

		$response = $this->buildAdminController($installer, $request)->removeTrustedSource();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['trustedPatterns' => []], $response->getData());
	}

	public function testAuditLogForbiddenForNonAdmin(): void {
		$response = $this->buildController()->auditLog();

		$this->assertSame(403, $response->getStatus());
	}

	public function testAuditLogAsAdminUsesDefaultPaginationAndNoAppFilter(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $default
		);

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->once())
			->method('findPage')
			->with(null, 50, 0)
			->willReturn([]);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, $mapper)->auditLog();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([], $data['entries']);
		$this->assertSame(50, $data['limit']);
		$this->assertSame(0, $data['offset']);
	}

	public function testAuditLogFiltersByAppIdAndClampsLimit(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'appId' => 'openregister',
				'limit' => '9999',
				'offset' => '50',
				default => $default,
			}
		);

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->once())
			->method('findPage')
			->with('openregister', 200, 50)
			->willReturn([]);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, $mapper)->auditLog();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(200, $response->getData()['limit']);
	}

	public function testAuditLogReturnsSerializedEntries(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $default
		);

		$entry = new AuditEntry();
		$entry->setId(1);
		$entry->setActorUid('alice');
		$entry->setAppId('openregister');
		$entry->setOperation('install');
		$entry->setFromVersion('2.5.0');
		$entry->setToVersion('2.3.0');
		$entry->setSourceId('appstore');
		$entry->setStatus('success');
		$entry->setMessage(null);
		$entry->setCreatedAt('2026-07-23 12:00:00');

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('findPage')->willReturn([$entry]);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, $mapper)->auditLog();

		$data = $response->getData();
		$this->assertCount(1, $data['entries']);
		$this->assertSame($entry, $data['entries'][0]);
		$this->assertSame([
			'id' => 1,
			'actorUid' => 'alice',
			'appId' => 'openregister',
			'operation' => 'install',
			'fromVersion' => '2.5.0',
			'toVersion' => '2.3.0',
			'sourceId' => 'appstore',
			'status' => 'success',
			'message' => null,
			'createdAt' => '2026-07-23 12:00:00',
		], $entry->jsonSerialize());
	}

	// --- Pin endpoints (see "Pin an installed app to its current version", "Unpin", "Honest pin presentation") ---

	public function testPinsForbiddenForNonAdmin(): void {
		$response = $this->buildController()->pins();

		$this->assertSame(403, $response->getStatus());
	}

	public function testPinAppForbiddenForNonAdmin(): void {
		$response = $this->buildController()->pinApp('openregister');

		$this->assertSame(403, $response->getStatus());
	}

	public function testUnpinAppForbiddenForNonAdmin(): void {
		$response = $this->buildController()->unpinApp('openregister');

		$this->assertSame(403, $response->getStatus());
	}

	public function testPinAppRejectsWhenAppNotInstalled(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $default
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, $appManager)->pinApp('openregister');

		$this->assertSame(400, $response->getStatus());
	}

	public function testPinAppRejectsVersionOtherThanInstalled(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'version' => '2.5.0',
				default => $default,
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('2.3.0');

		$pinStore = $this->createMock(PinStore::class);
		$pinStore->expects($this->never())->method('set');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, $pinStore, $appManager)->pinApp('openregister');

		$this->assertSame(400, $response->getStatus());
	}

	public function testPinAppSuccessWritesPinAtTheInstalledVersion(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'reason' => '2.5.0 breaks LDAP sync',
				default => $default,
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('2.3.0');

		$pinStore = $this->createMock(PinStore::class);
		$pinStore->expects($this->once())
			->method('set')
			->with(
				'openregister',
				$this->callback(function ($pin): bool {
					return $pin->version === '2.3.0'
						&& $pin->pinnedBy === 'admin'
						&& $pin->reason === '2.5.0 breaks LDAP sync';
				})
			);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, $pinStore, $appManager)->pinApp('openregister');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('openregister', $response->getData()['appId']);
		$this->assertSame('2.3.0', $response->getData()['pin']['version']);
	}

	public function testUnpinAppClearsThePinAsTheCurrentActor(): void {
		$request = $this->createMock(IRequest::class);

		$pinStore = $this->createMock(PinStore::class);
		$pinStore->expects($this->once())->method('clear')->with('openregister', 'admin');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, $pinStore)->unpinApp('openregister');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['unpinned']);
	}

	public function testPinsJoinsPersistedPinsWithTheLiveInstalledVersion(): void {
		$request = $this->createMock(IRequest::class);

		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('all')->willReturn([
			'openregister' => new \OCA\Versioniq\Service\Pin\Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00'),
		]);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('2.5.0');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, $pinStore, $appManager)->pins();

		$this->assertSame(200, $response->getStatus());
		$pins = $response->getData()['pins'];
		$this->assertCount(1, $pins);
		$this->assertSame('openregister', $pins[0]['appId']);
		$this->assertSame('2.3.0', $pins[0]['version']);
		$this->assertSame('2.5.0', $pins[0]['installedVersion']);
	}

	// --- Auto-update policy endpoints (see "Per-app update policy", "Global kill switch and window") ---

	public function testPoliciesForbiddenForNonAdmin(): void {
		$response = $this->buildController()->policies();

		$this->assertSame(403, $response->getStatus());
	}

	public function testSetPolicyForbiddenForNonAdmin(): void {
		$response = $this->buildController()->setPolicy('openregister');

		$this->assertSame(403, $response->getStatus());
	}

	public function testClearPolicyForbiddenForNonAdmin(): void {
		$response = $this->buildController()->clearPolicy('openregister');

		$this->assertSame(403, $response->getStatus());
	}

	public function testUpdateAutoUpdateSettingsForbiddenForNonAdmin(): void {
		$response = $this->buildController()->updateAutoUpdateSettings();

		$this->assertSame(403, $response->getStatus());
	}

	public function testPoliciesListsEveryPersistedPolicyPlusGlobalSettings(): void {
		$request = $this->createMock(IRequest::class);

		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->method('all')->willReturn([
			'openregister' => new \OCA\Versioniq\Service\Policy\Policy('patch', 'alice', '2026-07-23T00:00:00+00:00'),
		]);

		$settingsStore = $this->createMock(AutoUpdateSettingsStore::class);
		$settingsStore->method('isEnabled')->willReturn(true);
		$settingsStore->method('getWindow')->willReturn('01:00-05:00');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', $policyStore, $settingsStore)->policies();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(1, $data['policies']);
		$this->assertSame('openregister', $data['policies'][0]['appId']);
		$this->assertSame('patch', $data['policies'][0]['level']);
		$this->assertTrue($data['autoUpdateEnabled']);
		$this->assertSame('01:00-05:00', $data['autoUpdateWindow']);
	}

	public function testSetPolicyRejectsAnInvalidLevel(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'level' => 'yolo',
				default => $default,
			}
		);

		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->expects($this->never())->method('set');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', $policyStore)->setPolicy('openregister');

		$this->assertSame(400, $response->getStatus());
	}

	public function testSetPolicyRecordsLevelSetByAndSetAt(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'level' => 'patch',
				default => $default,
			}
		);

		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->expects($this->once())
			->method('set')
			->with(
				'openregister',
				$this->callback(function ($policy): bool {
					return $policy->level === 'patch' && $policy->setBy === 'alice';
				})
			);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'alice', $policyStore)->setPolicy('openregister');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('patch', $response->getData()['policy']['level']);
		$this->assertSame('alice', $response->getData()['policy']['setBy']);
	}

	public function testClearPolicyClearsTheStore(): void {
		$request = $this->createMock(IRequest::class);

		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->expects($this->once())->method('clear')->with('openregister');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', $policyStore)->clearPolicy('openregister');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['cleared']);
	}

	public function testUpdateAutoUpdateSettingsRejectsAMalformedWindow(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'window' => 'garbage',
				default => $default,
			}
		);

		$settingsStore = $this->createMock(AutoUpdateSettingsStore::class);
		$settingsStore->expects($this->never())->method('setWindow');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', null, $settingsStore)->updateAutoUpdateSettings();

		$this->assertSame(400, $response->getStatus());
	}

	public function testUpdateAutoUpdateSettingsWritesEnabledAndWindow(): void {
		$request = $this->createMock(IRequest::class);
		// `window` still arrives through stringParam()/getParam(), so its mock
		// stays. `enabled` is a declared parameter now and is passed as an
		// argument below — mocking it here would be mocking a path the method
		// no longer reads.
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'window' => '22:00-04:00',
				default => $default,
			}
		);

		$settingsStore = $this->createMock(AutoUpdateSettingsStore::class);
		$settingsStore->expects($this->once())->method('setEnabled')->with(true);
		$settingsStore->expects($this->once())->method('setWindow')->with('22:00-04:00');
		$settingsStore->method('isEnabled')->willReturn(true);
		$settingsStore->method('getWindow')->willReturn('22:00-04:00');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', null, $settingsStore)
			->updateAutoUpdateSettings('1');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['autoUpdateEnabled']);
		$this->assertSame('22:00-04:00', $response->getData()['autoUpdateWindow']);
	}

	// --- dryRun decoupled from debug (see MODIFIED "Debug Mode") ---

	// `debug` and `dryRun` are now DECLARED PARAMETERS of installVersion() rather
	// than values read out of the request with getParam(), so these tests pass
	// them as arguments. Nextcloud binds them from the same merged request
	// parameters at runtime, so the production behaviour is unchanged — but a
	// unit test calls the method directly, and a getParam() mock no longer
	// reaches it. Leaving the mocks in place would have left these three tests
	// silently exercising the DEFAULTS while still passing their status
	// assertions.
	public function testInstallVersionSendsExplicitDryRunFalseWithDebugTrueForARealInstallWithDebugTimeline(): void {
		$request = $this->createMock(IRequest::class);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('installAppVersion')
			->with('openregister', '2.3.0', true, null, null, false, false, false, false)
			->willReturn([
				'statusCode' => 200,
				'payload' => ['appId' => 'openregister', 'toVersion' => '2.3.0', 'dryRun' => false],
			]);

		$response = $this->buildAdminController($installer, $request)
			->installVersion('openregister', '2.3.0', null, '1', '0');

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayNotHasKey('deprecationNotice', $response->getData());
	}

	public function testInstallVersionSendsExplicitDryRunTrueWithNoDebugForASilentDryRun(): void {
		$request = $this->createMock(IRequest::class);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('installAppVersion')
			->with('openregister', '2.3.0', false, null, null, false, false, false, true)
			->willReturn([
				'statusCode' => 200,
				'payload' => ['appId' => 'openregister', 'toVersion' => '2.3.0', 'dryRun' => true],
			]);

		$response = $this->buildAdminController($installer, $request)
			->installVersion('openregister', '2.3.0', null, '0', '1');

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayNotHasKey('deprecationNotice', $response->getData());
	}

	// The one that matters most: `dryRun` is OMITTED here, not passed as '0'.
	// That distinction is the whole legacy path — only an absent dryRun lets
	// debug=1 still imply a dry run. A '0' default on the parameter would make
	// this test pass while the behaviour it pins was gone.
	public function testInstallVersionLegacyDebugAloneStillImpliesDryRunAndAddsADeprecationNotice(): void {
		$request = $this->createMock(IRequest::class);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('installAppVersion')
			->with('openregister', '2.3.0', true, null, null, false, false, false, null)
			->willReturn([
				'statusCode' => 200,
				'payload' => ['appId' => 'openregister', 'toVersion' => '2.3.0', 'dryRun' => true],
			]);

		$response = $this->buildAdminController($installer, $request)
			->installVersion('openregister', '2.3.0', null, '1');

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayHasKey('deprecationNotice', $response->getData());
	}

	/**
	 * Issue #437: a bind request for the self-hosted Forgejo or Gitea forge
	 * used to fall through to a GitHub binding, because only `codeberg` was
	 * recognised and everything else became `github`.
	 */
	public function testBindSourceForForgejoStoresAForgejoBinding(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'kind' => 'github-release',
				'forge' => 'forgejo',
				'owner' => 'acme',
				'repo' => 'widget',
				default => $default,
			}
		);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('bindSource')
			->with('widget', $this->callback(static fn (SourceBinding $binding): bool => $binding->getId() === 'forgejo:acme/widget'));

		$response = $this->buildAdminController($installer, $request)->bindSource('widget');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('forgejo:acme/widget', $response->getData()['sourceId']);
	}

	public function testBindSourceWithAnUnknownForgeIsRejected(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'kind' => 'github-release',
				'forge' => 'gitlab',
				'owner' => 'acme',
				'repo' => 'widget',
				default => $default,
			}
		);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->never())->method('bindSource');

		$response = $this->buildAdminController($installer, $request)->bindSource('widget');

		$this->assertSame(400, $response->getStatus());
	}
}
