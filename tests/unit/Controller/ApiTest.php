<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/AuditRows.php';

require_once __DIR__ . '/../Support/InstallArgs.php';

require_once __DIR__ . '/../Support/BuildsByParameterName.php';
require_once __DIR__ . '/../Support/DispatchesLikeNextcloud.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Advisory\AdvisoryTriageStore;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSchedule;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\UpdateGroup;
use OCA\Versioniq\Service\AutoUpdate\UpdateGroupStore;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\Installer\AppRestorer;
use OCA\Versioniq\Service\Installer\NoBackupException;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatValidator;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PolicySource;
use OCA\Versioniq\Service\Pin\SkipList;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Service\Release\KnownIssueStore;
use OCA\Versioniq\Service\Source\RetiredForgeException;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\DispatchesLikeNextcloud;
use OCA\Versioniq\Tests\Unit\Support\AuditRows;
use OCA\Versioniq\Tests\Unit\Support\InstallArgs;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ApiTest extends TestCase {
	use BuildsByParameterName;
	use DispatchesLikeNextcloud;

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
		?AttemptLedger $attemptLedger = null,
	): ApiController {
		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $request ?? $this->createMock(IRequest::class),
			'installerService' => $this->createMock(InstallerService::class),
			'groupManager' => $this->createMock(IGroupManager::class),
			'userSession' => $this->createMock(IUserSession::class),
			'serverVersion' => $this->fakeServerVersion(),
			'patMapper' => $this->createMock(PatMapper::class),
			'patManager' => $this->createMock(PatManager::class),
			'patValidator' => $this->createMock(PatValidator::class),
			'deeplinkBuilder' => $this->createMock(PatDeeplinkBuilder::class),
			'patExpiryEvaluator' => $this->createMock(PatExpiryEvaluator::class),
			'discoveryAggregator' => $this->createMock(DiscoveryAggregator::class),
			'advisoryResultStore' => $this->createMock(AdvisoryResultStore::class),
			'advisorySettingsStore' => $this->createMock(AdvisorySettingsStore::class),
			'auditEntryMapper' => $auditEntryMapper ?? $this->createMock(AuditEntryMapper::class),
			'pinStore' => $pinStore ?? $this->createMock(PinStore::class),
			'appManager' => $appManager ?? $this->createMock(IAppManager::class),
			'timeFactory' => $this->fakeTimeFactory(),
			'policyStore' => $policyStore ?? $this->createMock(PolicyStore::class),
			'autoUpdateSettingsStore' => $autoUpdateSettingsStore ?? $this->settingsStoreMock(),
			'settingsStore' => $autoUpdateSettingsStore ?? $this->settingsStoreMock(),
			'artifactCache' => $artifactCache ?? $this->createMock(ArtifactCache::class),
			'connectionReports' => null,
			'attemptLedger' => $attemptLedger ?? $this->createMock(AttemptLedger::class),
		]);
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
		?AttemptLedger $attemptLedger = null,
		?PolicySource $policySource = null,
		?SkipList $skipList = null,
		?KnownIssueStore $knownIssues = null,
		?UpdateGroupStore $groupStore = null,
	): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with($uid)->willReturn(true);

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $request,
			'installerService' => $installer,
			'groupManager' => $groupManager,
			'userSession' => $session,
			'serverVersion' => $this->fakeServerVersion(),
			'patMapper' => $this->createMock(PatMapper::class),
			'patManager' => $this->createMock(PatManager::class),
			'patValidator' => $this->createMock(PatValidator::class),
			'deeplinkBuilder' => $this->createMock(PatDeeplinkBuilder::class),
			'patExpiryEvaluator' => $this->createMock(PatExpiryEvaluator::class),
			'discoveryAggregator' => $this->createMock(DiscoveryAggregator::class),
			'advisoryResultStore' => $this->createMock(AdvisoryResultStore::class),
			'advisorySettingsStore' => $this->createMock(AdvisorySettingsStore::class),
			'auditEntryMapper' => $auditEntryMapper ?? $this->createMock(AuditEntryMapper::class),
			'pinStore' => $pinStore ?? $this->createMock(PinStore::class),
			'appManager' => $appManager ?? $this->createMock(IAppManager::class),
			'timeFactory' => $this->fakeTimeFactory(),
			'policyStore' => $policyStore ?? $this->createMock(PolicyStore::class),
			'autoUpdateSettingsStore' => $autoUpdateSettingsStore ?? $this->settingsStoreMock(),
			'settingsStore' => $autoUpdateSettingsStore ?? $this->settingsStoreMock(),
			'artifactCache' => $artifactCache ?? $this->createMock(ArtifactCache::class),
			'connectionReports' => null,
			'attemptLedger' => $attemptLedger ?? $this->createMock(AttemptLedger::class),
			'policySource' => $policySource,
			'skipList' => $skipList,
			'knownIssues' => $knownIssues,
			'groupStore' => $groupStore ?? $this->createMock(UpdateGroupStore::class),
		]);
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

	/**
	 * `GET /api/admin-check` had no caller: the page is an admin setting
	 * (not delegable), so it only renders for admins, and every endpoint
	 * already answers 403 to anyone else. A route nobody calls is surface
	 * nobody tests, so it is gone (#438 item 8).
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function testTheUncalledAdminCheckRouteIsGone(): void {
		$this->assertFalse(method_exists(ApiController::class, 'adminCheck'));
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

	/**
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 */
	public function testAppOutcomesForbiddenForNonAdmin(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->never())->method('countOutcomesByVersion');

		$response = $this->buildController(null, $mapper)->appOutcomes('hermiq');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'Forbidden'], $response->getData());
	}

	/**
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 */
	public function testAppOutcomesAsAdminReturnsTheCountsPerVersion(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->once())->method('countOutcomesByVersion')->with('hermiq')
			->willReturn(['1.4.0' => ['succeeded' => 3, 'failed' => 1]]);

		$response = $this->buildAdminController($this->createMock(InstallerService::class), $this->createMock(IRequest::class), $mapper)->appOutcomes(' hermiq ');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['appId' => 'hermiq', 'outcomes' => ['1.4.0' => ['succeeded' => 3, 'failed' => 1]]], $response->getData());
	}

	/**
	 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
	 */
	public function testKnownIssueEndpointsAreAdminOnly(): void {
		$controller = $this->buildController();

		$this->assertSame(403, $controller->addKnownIssue('openregister', '2.4.1', 'Broken')->getStatus());
		$this->assertSame(403, $controller->removeKnownIssue('openregister', '2.4.1', 0)->getStatus());
	}

	/**
	 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
	 */
	public function testKnownIssueEndpointsChangeTheStoreAsTheAdmin(): void {
		$store = $this->createMock(KnownIssueStore::class);
		$store->expects($this->once())->method('add')
			->with(['appId' => 'openregister', 'version' => '2.4.1', 'actor' => 'admin'], ['text' => 'Export times out', 'workaround' => 'Per schema', 'link' => null])
			->willReturn([['text' => 'Export times out']]);
		$store->expects($this->exactly(2))->method('remove')
			->willReturnOnConsecutiveCalls([], $this->throwException(new \InvalidArgumentException('This version has no known issue at that position.')));

		$controller = $this->buildAdminController($this->createMock(InstallerService::class), $this->createMock(IRequest::class), knownIssues: $store);

		$added = $controller->addKnownIssue(' openregister ', '2.4.1', 'Export times out', 'Per schema');
		$this->assertSame(200, $added->getStatus());
		$this->assertSame(['appId' => 'openregister', 'version' => '2.4.1', 'knownIssues' => [['text' => 'Export times out']]], $added->getData());
		$this->assertSame(200, $controller->removeKnownIssue('openregister', '2.4.1', 0)->getStatus());
		$refused = $controller->removeKnownIssue('openregister', '2.4.1', 9);
		$this->assertSame(400, $refused->getStatus());
		$this->assertSame(['message' => 'This version has no known issue at that position.'], $refused->getData());
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
			// A row from before origins were recorded: unknown.
			'origin' => null,
			// audit-security-reports D2: null for an entry about no advisory.
			'advisories' => null,
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

		$settingsStore = $this->settingsStoreMock();
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

	/**
	 * @spec openspec/changes/autoupdate-security-first/tasks.md#task-1.1
	 */
	public function testSetPolicyStoresTheSecurityLevel(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'level' => 'security',
				default => $default,
			}
		);

		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->expects($this->once())
			->method('set')
			->with('openregister', $this->callback(static fn ($policy): bool => $policy->level === 'security'));

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'alice', $policyStore)->setPolicy('openregister');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('security', $response->getData()['policy']['level']);
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

		$settingsStore = $this->settingsStoreMock();
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

		$settingsStore = $this->settingsStoreMock();
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

	/**
	 * A request whose getParam answers these values and the default for anything else.
	 *
	 * @param array<string, mixed> $params
	 */
	private function paramsRequest(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => array_key_exists($name, $params) ? $params[$name] : $default
		);

		return $request;
	}

	/**
	 * autoupdate-run-batching D4: a cap outside 1 to 50 (per run) or 1 to 200
	 * (per week) is refused with 400 and nothing is stored.
	 *
	 * @dataProvider badCapProvider
	 */
	public function testUpdateAutoUpdateSettingsRefusesACapOutOfRange(string $name, mixed $value): void {
		$settingsStore = $this->createMock(AutoUpdateSettingsStore::class);
		$settingsStore->expects($this->never())->method('setMaxPerRun');
		$settingsStore->expects($this->never())->method('setMaxPerWeek');
		$settingsStore->expects($this->never())->method('setWindow');

		$request = $this->paramsRequest([$name => $value, 'window' => '22:00-04:00']);
		$response = $this->buildAdminController($this->createMock(InstallerService::class), $request, null, null, null, 'admin', null, $settingsStore)
			->updateAutoUpdateSettings();

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString($name, $response->getData()['message']);
	}

	/**
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function badCapProvider(): array {
		return [
			'per run zero' => ['maxPerRun', '0'],
			'per run 51' => ['maxPerRun', '51'],
			'per week 201' => ['maxPerWeek', 201],
			'per run text' => ['maxPerRun', 'five'],
			'per week fraction' => ['maxPerWeek', '2.5'],
		];
	}

	public function testUpdateAutoUpdateSettingsStoresACapAndClearsAnEmptyOne(): void {
		$settingsStore = $this->settingsStoreMock();
		$settingsStore->expects($this->once())->method('setMaxPerRun')->with(5);
		$settingsStore->expects($this->once())->method('setMaxPerWeek')->with(null);
		$settingsStore->method('getMaxPerRun')->willReturn(5);
		$settingsStore->method('getMaxPerWeek')->willReturn(null);
		$settingsStore->expects($this->never())->method('setEnabled');

		$request = $this->paramsRequest(['maxPerRun' => '5', 'maxPerWeek' => '']);
		$response = $this->buildAdminController($this->createMock(InstallerService::class), $request, null, null, null, 'admin', null, $settingsStore)
			->updateAutoUpdateSettings();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(5, $response->getData()['maxPerRun']);
		$this->assertNull($response->getData()['maxPerWeek']);
	}

	public function testUpdateAutoUpdateSettingsLeavesTheCapsAloneWhenNotSent(): void {
		$settingsStore = $this->settingsStoreMock();
		$settingsStore->expects($this->never())->method('setMaxPerRun');
		$settingsStore->expects($this->never())->method('setMaxPerWeek');
		$settingsStore->method('getMaxPerWeek')->willReturn(40);

		$response = $this->buildAdminController($this->createMock(InstallerService::class), $this->paramsRequest([]), null, null, null, 'admin', null, $settingsStore)
			->updateAutoUpdateSettings();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(40, $response->getData()['maxPerWeek']);
	}

	/**
	 * An admin controller for the groups endpoint, with every app manageable
	 * except `notmanaged`.
	 */
	private function groupsController(IRequest $request, UpdateGroupStore $groupStore): ApiController {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('isManageableApp')->willReturnCallback(static fn (string $appId): bool => $appId !== 'notmanaged');

		return $this->buildAdminController($installer, $request, groupStore: $groupStore);
	}

	public function testUpdateAutoUpdateGroupsIsForbiddenForANonAdmin(): void {
		$groupStore = $this->createMock(UpdateGroupStore::class);
		$groupStore->expects($this->never())->method('replaceAll');
		$controller = $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $this->paramsRequest(['groups' => [['name' => 'Registers', 'apps' => ['openregister', 'opencatalogi']]]]),
			'serverVersion' => $this->fakeServerVersion(),
			'connectionReports' => null,
			'knownIssues' => null,
			'groupStore' => $groupStore,
		]);

		$this->assertSame(403, $controller->updateAutoUpdateGroups()->getStatus());
	}

	/**
	 * autoupdate-run-batching D2: the whole list is refused with 400 and
	 * nothing is stored.
	 *
	 * @dataProvider badGroupsProvider
	 */
	public function testUpdateAutoUpdateGroupsRefusesABadList(mixed $groups, string $message): void {
		$groupStore = $this->createMock(UpdateGroupStore::class);
		$groupStore->expects($this->never())->method('replaceAll');

		$response = $this->groupsController($this->paramsRequest(['groups' => $groups]), $groupStore)->updateAutoUpdateGroups();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame($message, $response->getData()['message']);
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function badGroupsProvider(): array {
		return [
			'a duplicate name' => [
				[['name' => 'Registers', 'apps' => ['a', 'b']], ['name' => 'registers', 'apps' => ['c', 'd']]],
				'The group name "registers" is used twice.',
			],
			'an app in two groups' => [
				[['name' => 'One', 'apps' => ['a', 'b']], ['name' => 'Two', 'apps' => ['b', 'c']]],
				'b is in two groups: "One" and "Two".',
			],
			'a group of one' => [
				[['name' => 'Solo', 'apps' => ['a']]],
				'Group "Solo" must hold 2 to 20 different apps.',
			],
			'an app this instance cannot manage' => [
				[['name' => 'One', 'apps' => ['a', 'notmanaged']]],
				'notmanaged cannot be updated automatically on this instance.',
			],
			'no name' => [[['apps' => ['a', 'b']]], 'Every group needs a name.'],
			'not a list' => ['Registers', 'groups must be a list.'],
		];
	}

	public function testUpdateAutoUpdateGroupsStoresTheListInOrder(): void {
		$groupStore = $this->createMock(UpdateGroupStore::class);
		$stored = null;
		$groupStore->expects($this->once())->method('replaceAll')->willReturnCallback(static function (array $groups) use (&$stored): void {
			$stored = $groups;
		});
		$request = $this->paramsRequest(['groups' => [
			['id' => 'g1', 'name' => 'Registers', 'apps' => ['openregister', 'opencatalogi']],
			['name' => 'Docs', 'apps' => ['docudesk', 'pipelinq']],
		]]);

		$response = $this->groupsController($request, $groupStore)->updateAutoUpdateGroups();

		$this->assertSame(200, $response->getStatus());
		$this->assertIsArray($stored);
		$this->assertSame(['g1', 'Registers', ['openregister', 'opencatalogi'], 'admin', '2026-07-24T00:00:00+00:00'], [$stored[0]->id, $stored[0]->name, $stored[0]->apps, $stored[0]->setBy, $stored[0]->setAt]);
		$this->assertSame(['docudesk', 'pipelinq'], $stored[1]->apps);
		$this->assertNotSame('', $stored[1]->id);
		$this->assertSame(['Registers', 'Docs'], array_column($response->getData()['groups'], 'name'));
	}

	public function testPoliciesReturnsTheGroupsAndTheWaitingList(): void {
		$settingsStore = $this->settingsStoreMock();
		$settingsStore->method('getDeferred')->willReturn(['group:Registers' => '2026-07-20T02:00:00+00:00']);
		$groupStore = $this->createMock(UpdateGroupStore::class);
		$groupStore->method('all')->willReturn([
			new UpdateGroup('g1', 'Registers', ['openregister', 'opencatalogi'], ['by' => 'admin', 'at' => '2026-07-01T00:00:00+00:00']),
		]);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $this->paramsRequest([]), autoUpdateSettingsStore: $settingsStore, groupStore: $groupStore)->policies();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([[
			'id' => 'g1',
			'name' => 'Registers',
			'apps' => ['openregister', 'opencatalogi'],
			'setBy' => 'admin',
			'setAt' => '2026-07-01T00:00:00+00:00',
		]], $response->getData()['groups']);
		$this->assertEquals((object)['group:Registers' => '2026-07-20T02:00:00+00:00'], $response->getData()['waiting']);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalidSchedules(): array {
		return [
			'weekday 8' => [['major' => ['days' => [8]]]],
			'unknown kind' => [['weekly' => ['days' => [1]]]],
			'string monthly' => [['minor' => ['days' => [1], 'monthly' => 'yes']]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidSchedules')]
	public function testUpdateAutoUpdateSettingsRejectsAnInvalidScheduleBeforeAnyWrite(mixed $schedule): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'window' => '22:00-04:00',
				'schedule' => $schedule,
				default => $default,
			}
		);

		$settingsStore = $this->settingsStoreMock();
		$settingsStore->expects($this->never())->method('setEnabled');
		$settingsStore->expects($this->never())->method('setWindow');
		$settingsStore->expects($this->never())->method('setSchedule');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', null, $settingsStore)
			->updateAutoUpdateSettings('1');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringStartsWith('schedule', $response->getData()['message']);
	}

	public function testUpdateAutoUpdateSettingsStoresAValidSchedule(): void {
		$schedule = ['major' => ['days' => [6], 'monthly' => true]];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'schedule' => $schedule,
				default => $default,
			}
		);

		$settingsStore = $this->settingsStoreMock();
		$settingsStore->expects($this->once())->method('setSchedule')->with(
			$this->callback(static fn (AutoUpdateSchedule $stored): bool => $stored->toArray() === AutoUpdateSchedule::fromArray($schedule)->toArray())
		);
		$settingsStore->method('getWindow')->willReturn('01:00-05:00');
		$settingsStore->method('getTimeZoneName')->willReturn('UTC');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', null, $settingsStore)
			->updateAutoUpdateSettings();

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayHasKey('nextRuns', $response->getData());
		$this->assertArrayHasKey('autoUpdateSchedule', $response->getData());
	}

	public function testUpdateAutoUpdateSettingsStoresTheDefaultLevel(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'defaultLevel' => 'patch',
				default => $default,
			}
		);

		$settingsStore = $this->settingsStoreMock();
		$settingsStore->expects($this->once())->method('setDefaultLevel')->with('patch');
		$settingsStore->expects($this->never())->method('setEnabled');
		$settingsStore->expects($this->never())->method('setWindow');
		$settingsStore->method('getDefaultLevel')->willReturn('patch');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', null, $settingsStore)->updateAutoUpdateSettings();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('patch', $response->getData()['autoUpdateDefaultLevel']);
	}

	public function testUpdateAutoUpdateSettingsRejectsAnUnknownDefaultLevelAndWritesNothing(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'defaultLevel' => 'everything',
				'window' => '22:00-04:00',
				default => $default,
			}
		);

		$settingsStore = $this->settingsStoreMock();
		$settingsStore->expects($this->never())->method('setDefaultLevel');
		$settingsStore->expects($this->never())->method('setWindow');
		$settingsStore->expects($this->never())->method('setEnabled');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', null, $settingsStore)->updateAutoUpdateSettings('1');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('defaultLevel must be one of', $response->getData()['message']);
	}

	/**
	 * A PolicySource whose config.php names a temporary file holding $json.
	 */
	private function policyFileSource(string $json): PolicySource {
		$path = (string)tempnam(sys_get_temp_dir(), 'vq-api');
		file_put_contents($path, $json);
		register_shutdown_function(static fn () => @unlink($path));
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($path);

		return new PolicySource($config, $this->createMock(PolicyStore::class), $this->settingsStoreMock());
	}

	/**
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	public function testInFileModeThePoliciesComeFromTheFileAndNameIt(): void {
		$source = $this->policyFileSource('{"schemaVersion":1,"autoUpdate":{"enabled":true,"defaultLevel":"patch"},"policies":{"openregister":"minor"}}');
		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->expects($this->never())->method('all');

		$installer = $this->createMock(InstallerService::class);
		$data = $this->buildAdminController($installer, $this->createMock(IRequest::class), null, null, null, 'admin', $policyStore, null, null, null, $source)->policies()->getData();

		$this->assertSame([['appId' => 'openregister', 'level' => 'minor', 'setBy' => 'file', 'setAt' => '2026-07-24T00:00:00+00:00', 'blockedVersions' => []]], $data['policies']);
		$this->assertTrue($data['autoUpdateEnabled']);
		$this->assertSame('01:00-05:00', $data['autoUpdateWindow']);
		$this->assertSame('patch', $data['autoUpdateDefaultLevel']);
		$this->assertSame($source->filePath(), $data['managedBy']['file']);
		$this->assertNull($data['managedBy']['error']);
	}

	/**
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	public function testABrokenPolicyFileIsShownWithItsErrorAndNothingOn(): void {
		$source = $this->policyFileSource('{"schemaVersion":1,"policies":{"openregister":"weekly"}}');

		$installer = $this->createMock(InstallerService::class);
		$data = $this->buildAdminController($installer, $this->createMock(IRequest::class), null, null, null, 'admin', null, null, null, null, $source)->policies()->getData();

		$this->assertSame([], $data['policies']);
		$this->assertFalse($data['autoUpdateEnabled']);
		$this->assertSame('$.policies.openregister: must be one of none, patch, minor, all, security', $data['managedBy']['error']);
	}

	/**
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	public function testPolicyAndSettingsWritesAnswer409NamingTheFileInFileMode(): void {
		$source = $this->policyFileSource('{"schemaVersion":1,"policies":{}}');
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $name === 'level' ? 'patch' : $default
		);
		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->expects($this->never())->method('set');
		$policyStore->expects($this->never())->method('clear');
		$settingsStore = $this->settingsStoreMock();
		$settingsStore->expects($this->never())->method('setEnabled');

		$installer = $this->createMock(InstallerService::class);
		$controller = $this->buildAdminController($installer, $request, null, null, null, 'admin', $policyStore, $settingsStore, null, null, $source);

		foreach ([$controller->setPolicy('openregister'), $controller->clearPolicy('openregister'), $controller->updateAutoUpdateSettings('1')] as $response) {
			$this->assertSame(409, $response->getStatus());
			$this->assertStringContainsString((string)$source->filePath(), $response->getData()['message']);
		}
	}

	public function testOutsideFileModeThePoliciesSayNoFileManagesThem(): void {
		$installer = $this->createMock(InstallerService::class);
		$data = $this->buildAdminController($installer, $this->createMock(IRequest::class))->policies()->getData();

		$this->assertNull($data['managedBy']);
	}

	public function testPoliciesCarryTheDefaultLevel(): void {
		$settingsStore = $this->settingsStoreMock();
		$settingsStore->method('getDefaultLevel')->willReturn('minor');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $this->createMock(IRequest::class), null, null, null, 'admin', $this->createMock(PolicyStore::class), $settingsStore)->policies();

		$this->assertSame('minor', $response->getData()['autoUpdateDefaultLevel']);
	}

	// --- dryRun decoupled from debug (see MODIFIED "Debug Mode") ---

	// These go through dispatch(), which fills the request values the way the
	// Nextcloud Dispatcher does, so they hold whether installVersion() declares
	// `debug` and `dryRun` as parameters or reads them from the request.
	public function testInstallVersionSendsExplicitDryRunFalseWithDebugTrueForARealInstallWithDebugTimeline(): void {
		$request = $this->requestWith(['appId' => 'openregister', 'version' => '2.3.0'] + ['debug' => '1', 'dryRun' => '0']);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('installAppVersion')
			->with(...InstallArgs::expected('openregister', '2.3.0', true, null, null, false, false, false, false))
			->willReturn([
				'statusCode' => 200,
				'payload' => ['appId' => 'openregister', 'toVersion' => '2.3.0', 'dryRun' => false],
			]);

		$response = $this->dispatch($this->buildAdminController($installer, $request), 'installVersion', $request);

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayNotHasKey('deprecationNotice', $response->getData());
	}

	public function testInstallVersionSendsExplicitDryRunTrueWithNoDebugForASilentDryRun(): void {
		$request = $this->requestWith(['appId' => 'openregister', 'version' => '2.3.0'] + ['debug' => '0', 'dryRun' => '1']);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('installAppVersion')
			->with(...InstallArgs::expected('openregister', '2.3.0', false, null, null, false, false, false, true))
			->willReturn([
				'statusCode' => 200,
				'payload' => ['appId' => 'openregister', 'toVersion' => '2.3.0', 'dryRun' => true],
			]);

		$response = $this->dispatch($this->buildAdminController($installer, $request), 'installVersion', $request);

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayNotHasKey('deprecationNotice', $response->getData());
	}

	// The one that matters most: `dryRun` is OMITTED here, not passed as '0'.
	// That distinction is the whole legacy path — only an absent dryRun lets
	// debug=1 still imply a dry run. A '0' default on the parameter would make
	// this test pass while the behaviour it pins was gone.
	public function testInstallVersionLegacyDebugAloneStillImpliesDryRunAndAddsADeprecationNotice(): void {
		$request = $this->requestWith(['appId' => 'openregister', 'version' => '2.3.0'] + ['debug' => '1']);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('installAppVersion')
			->with(...InstallArgs::expected('openregister', '2.3.0', true, null, null, false, false, false, null))
			->willReturn([
				'statusCode' => 200,
				'payload' => ['appId' => 'openregister', 'toVersion' => '2.3.0', 'dryRun' => true],
			]);

		$response = $this->dispatch($this->buildAdminController($installer, $request), 'installVersion', $request);

		$this->assertSame(200, $response->getStatus());
		$this->assertArrayHasKey('deprecationNotice', $response->getData());
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unrecognisedDryRuns(): array {
		return ['yes' => ['yes'], 'on' => ['on'], 'x' => ['x'], 'empty' => [''], 'one point zero' => ['1.0'], 'leading zero' => ['01']];
	}

	/**
	 * An explicit dryRun that is not 1, true, 0 or false is refused: read as
	 * false it would turn `debug=1&dryRun=yes` into a real install.
	 *
	 * @dataProvider unrecognisedDryRuns
	 */
	public function testInstallVersionRefusesAnUnrecognisedDryRun(string $dryRun): void {
		$request = $this->requestWith(['appId' => 'openregister', 'version' => '2.3.0', 'debug' => '1', 'dryRun' => $dryRun]);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->never())->method('installAppVersion');

		$response = $this->dispatch($this->buildAdminController($installer, $request), 'installVersion', $request);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'dryRun must be 1, true, 0 or false.'], $response->getData());
	}

	/**
	 * A debug that is not 1, true, 0 or false is refused: read as false,
	 * `debug=yes` without dryRun would be a real install instead of the
	 * dry run that debug implies.
	 *
	 * @dataProvider unrecognisedDryRuns
	 */
	public function testInstallVersionRefusesAnUnrecognisedDebug(string $debug): void {
		$request = $this->requestWith(['appId' => 'openregister', 'version' => '2.3.0', 'debug' => $debug]);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->never())->method('installAppVersion');

		$response = $this->dispatch($this->buildAdminController($installer, $request), 'installVersion', $request);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'debug must be 1, true, 0 or false.'], $response->getData());
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
				'forge' => 'bitbucket',
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

	/**
	 * codeberg-is-no-release-source: a new Codeberg binding is a 400 naming
	 * the forge, and the binding is not stored.
	 */
	public function testBindSourceOnTheRetiredCodebergForgeIsRefused(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'kind' => 'github-release',
				'forge' => 'codeberg',
				'owner' => 'Conduction',
				'repo' => 'openregister',
				default => $default,
			}
		);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('bindSource')->willThrowException(new RetiredForgeException('codeberg'));
		$installer->expects($this->never())->method('getBinding');

		$response = $this->buildAdminController($installer, $request)->bindSource('openregister');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('"codeberg" is no longer offered', $response->getData()['message']);
	}

	/**
	 * codeberg-is-no-release-source: a new Codeberg trusted pattern is a 400
	 * naming the forge, not the 422 of a malformed pattern.
	 */
	public function testAddTrustedSourceOnTheRetiredCodebergForgeIsRefused(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'forge' => 'codeberg',
				'owner' => 'Conduction',
				default => $default,
			}
		);

		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())
			->method('addTrustedPattern')
			->with('codeberg', 'Conduction', null)
			->willThrowException(new RetiredForgeException('codeberg'));

		$response = $this->buildAdminController($installer, $request)->addTrustedSource();

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('"codeberg" is no longer offered', $response->getData()['message']);
	}

	public function testPoliciesListEachAppsBlockedVersions(): void {
		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->method('all')->willReturn([
			'openregister' => new \OCA\Versioniq\Service\Policy\Policy('patch', 'alice', '2026-07-23T00:00:00+00:00'),
		]);
		$ledger = $this->createMock(AttemptLedger::class);
		$ledger->method('blockedVersions')->with('openregister')->willReturn([['version' => '2.3.4', 'at' => '2026-07-22T02:00:00+00:00']]);

		$response = $this->buildAdminController($this->createMock(InstallerService::class), $this->createMock(IRequest::class), null, null, null, 'admin', $policyStore, null, null, $ledger)->policies();

		$this->assertSame([['version' => '2.3.4', 'at' => '2026-07-22T02:00:00+00:00']], $response->getData()['policies'][0]['blockedVersions']);
	}

	public function testRetryAttemptForgetsTheVersion(): void {
		$ledger = $this->createMock(AttemptLedger::class);
		$ledger->expects($this->once())->method('forget')->with('openregister', '2.3.4')->willReturn(true);

		$response = $this->buildAdminController($this->createMock(InstallerService::class), $this->createMock(IRequest::class), null, null, null, 'admin', null, null, null, $ledger)->retryAttempt('openregister', '2.3.4');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['cleared']);
	}

	public function testRetryAttemptOnAnUnknownVersionIs404(): void {
		$ledger = $this->createMock(AttemptLedger::class);
		$ledger->method('forget')->willReturn(false);

		$response = $this->buildAdminController($this->createMock(InstallerService::class), $this->createMock(IRequest::class), null, null, null, 'admin', null, null, null, $ledger)->retryAttempt('openregister', '9.9.9');

		$this->assertSame(404, $response->getStatus());
	}

	public function testRetryAttemptForbiddenForNonAdmin(): void {
		$this->assertSame(403, $this->buildController()->retryAttempt('openregister', '2.3.4')->getStatus());
	}

	public function testClearBindingRemovesTheBinding(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('clearBinding')->with('openregister');

		$response = $this->buildAdminController($installer, $this->createMock(IRequest::class))->clearBinding('openregister');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('appstore', $response->getData()['sourceId']);
	}

	public function testClearBindingForbiddenForNonAdmin(): void {
		$this->assertSame(403, $this->buildController()->clearBinding('openregister')->getStatus());
	}

	// --- pinning-ranges-and-skips ---

	/**
	 * @param array<string, mixed> $params
	 */
	private function requestWith(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default);

		return $request;
	}

	public function testSetPolicyStoresTheHoldManualSwitch(): void {
		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->expects($this->once())->method('set')->with('openregister', $this->callback(static fn ($policy): bool => $policy->level === 'patch' && $policy->holdManual === true));

		$response = $this->buildAdminController($this->createMock(InstallerService::class), $this->requestWith(['level' => 'patch', 'holdManual' => true]), null, null, null, 'admin', $policyStore)->setPolicy('openregister');

		$this->assertTrue($response->getData()['policy']['holdManual']);
	}

	public function testSetPolicyWithoutTheSwitchKeepsTheStoredOne(): void {
		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->method('get')->willReturn(new Policy('patch', 'admin', '2026-01-01T00:00:00+00:00', holdManual: true));
		$policyStore->expects($this->once())->method('set')->with('openregister', $this->callback(static fn ($policy): bool => $policy->level === 'minor' && $policy->holdManual === true));

		$this->buildAdminController($this->createMock(InstallerService::class), $this->requestWith(['level' => 'minor']), null, null, null, 'admin', $policyStore)->setPolicy('openregister');
	}

	public function testSkipVersionStoresTheSkipWithItsReason(): void {
		$skipList = $this->createMock(SkipList::class);
		$skipList->expects($this->once())->method('skip')->with('openregister', '2.3.4', $this->callback(static fn (array $stamp): bool => $stamp['by'] === 'admin' && is_string($stamp['at'])), 'breaks the import')->willReturn(true);

		$response = $this->buildAdminController($this->createMock(InstallerService::class), $this->requestWith(['reason' => 'breaks the import']), null, null, null, 'admin', skipList: $skipList)->skipVersion('openregister', '2.3.4');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['appId' => 'openregister', 'version' => '2.3.4', 'skipped' => true], $response->getData());
	}

	public function testSkipVersionRefusesAnEmptyVersion(): void {
		$skipList = $this->createMock(SkipList::class);
		$skipList->expects($this->never())->method('skip');

		$response = $this->buildAdminController($this->createMock(InstallerService::class), $this->requestWith([]), null, null, null, 'admin', skipList: $skipList)->skipVersion('openregister', ' ');

		$this->assertSame(400, $response->getStatus());
	}

	public function testUnskipAndListTheSkips(): void {
		$skipList = $this->createMock(SkipList::class);
		$skipList->expects($this->once())->method('unskip')->with('openregister', '2.3.4', 'admin')->willReturn(true);
		$skipList->method('entries')->willReturn([['version' => '2.3.5', 'skippedBy' => 'admin', 'skippedAt' => 'T', 'reason' => '']]);
		$controller = $this->buildAdminController($this->createMock(InstallerService::class), $this->requestWith([]), null, null, null, 'admin', skipList: $skipList);

		$this->assertTrue($controller->unskipVersion('openregister', '2.3.4')->getData()['unskipped']);
		$this->assertSame('2.3.5', $controller->skippedVersions('openregister')->getData()['skipped'][0]['version']);
	}

	/**
	 * A request that answers the named parameters and the default otherwise.
	 */
	private function auditRequest(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default
		);
		return $request;
	}

	public function testAuditLogCutsAPeriodInTheAdminsTimeZone(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->never())->method('findPage');
		$mapper->expects($this->once())
			->method('findBetween')
			->with('2026-10-31 23:00:00', '2026-11-30 22:59:59', 'fixtureapp', 'install', 50, 0, true)
			->willReturn([AuditRows::entry(2, '2026-11-02 09:00:00', 'install', ['to' => '1.0.1'])]);

		$request = $this->auditRequest(['from' => '2026-11-01', 'to' => '2026-11-30', 'timeZone' => 'Europe/Amsterdam', 'appId' => 'fixtureapp', 'operation' => 'install']);
		$response = $this->buildAdminController($this->createMock(InstallerService::class), $request, $mapper)->auditLog();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([2], array_column($data['entries'], 'id'));
		$this->assertNull($data['total']);
	}

	public function testAuditLogSecurityOnlyNamesTheFixedAdvisoryAndLeavesOutAnUnresolvedInstall(): void {
		$advisory = [['id' => 'GHSA-1', 'severity' => 'high']];
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->once())
			->method('findBetween')
			->with('2026-11-14 00:00:00', null, null, null, 20000)
			->willReturn([
				AuditRows::entry(1, '2026-11-14 08:00:00', 'install', ['appId' => 'other', 'to' => '2.0.0']),
				AuditRows::entry(2, '2026-11-14 09:00:00', 'install', ['from' => '1.0.0', 'to' => '1.0.1']),
				AuditRows::entry(3, '2026-11-14 22:00:00', 'install', ['appId' => 'late', 'to' => '3.0.0']),
				AuditRows::entry(4, '2026-11-15 00:10:00', 'advisory_resolved', ['from' => '1.0.0', 'to' => '1.0.1', 'message' => 'update', 'advisories' => $advisory]),
				AuditRows::entry(5, '2026-11-15 00:20:00', 'advisory_resolved', ['appId' => 'late', 'to' => '3.0.0', 'message' => 'update', 'advisories' => [['id' => 'GHSA-3', 'severity' => 'low']]]),
			]);

		$request = $this->auditRequest(['from' => '2026-11-14', 'to' => '2026-11-14', 'securityOnly' => 'true']);
		$response = $this->buildAdminController($this->createMock(InstallerService::class), $request, $mapper)->auditLog();

		$data = $response->getData();
		$this->assertSame(2, $data['total']);
		$this->assertSame([3, 2], array_column($data['entries'], 'id'));
		$this->assertSame($advisory, $data['entries'][1]['fixedAdvisories']);
		$this->assertSame('1.0.1', $data['entries'][1]['toVersion']);
	}

	public function testAuditLogRefusesABadPeriod(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->never())->method('findBetween');

		$request = $this->auditRequest(['from' => '2026-13-01']);
		$response = $this->buildAdminController($this->createMock(InstallerService::class), $request, $mapper)->auditLog();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'Dates are YYYY-MM-DD.'], $response->getData());
	}

	/**
	 * A settings-store double whose schedule is the default every-day schedule.
	 *
	 * @return AutoUpdateSettingsStore&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function settingsStoreMock(): AutoUpdateSettingsStore {
		$store = $this->createMock(AutoUpdateSettingsStore::class);
		$store->method('getSchedule')->willReturn(AutoUpdateSchedule::everyDay());
		return $store;
	}

	/**
	 * GET /api/advisories returns the raw snapshot plus the stored triage
	 * map, keyed `{appId}|{advisoryId}`, for the page (advisories-triage D1, D4).
	 */
	public function testAdvisoriesCarriesTheTriageMap(): void {
		$triage = ['notes|GHSA-1' => ['dismissal' => ['reason' => 'not_used', 'by' => 'alice', 'installedVersion' => '4.1.0']]];
		$results = $this->createMock(AdvisoryResultStore::class);
		$results->method('read')->willReturn(['advisories' => ['notes' => ['state' => 'advisory-available']], 'checkedAt' => 1_700_000_000, 'status' => 'ok', 'errors' => []]);
		$triageStore = $this->createMock(AdvisoryTriageStore::class);
		$triageStore->expects($this->once())->method('read')->willReturn($triage);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin')->willReturn(true);

		$controller = $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $this->createMock(IRequest::class),
			'groupManager' => $groupManager,
			'userSession' => $session,
			'serverVersion' => $this->fakeServerVersion(),
			'timeFactory' => $this->fakeTimeFactory(),
			'advisoryResultStore' => $results,
			'advisoryTriageStore' => $triageStore,
			'connectionReports' => null,
		]);

		$response = $controller->advisories();

		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(['notes' => ['state' => 'advisory-available', 'heldByPin' => false]], $data['advisories']);
		$this->assertSame(1_700_000_000, $data['checkedAt']);
		$this->assertSame(['advisories', 'checkedAt', 'status', 'errors', 'compliance', 'triage', 'libraries', 'librariesCheckedAt'], array_keys($data));
		$this->assertSame('ok', $data['status']);
		$this->assertSame([], $data['errors']);
		$this->assertSame($triage, $data['triage']);
	}

	/**
	 * POST /api/apps/{appId}/restore puts the app back from its backup
	 * (safety-rollback-backup-and-preflight D4).
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-restoring-a-broken-install-puts-back-files-and-tables
	 */
	public function testRestoreAppReturnsWhatWasRestored(): void {
		$restored = ['appId' => 'dossiq', 'version' => '0.4.48', 'enabled' => 'yes', 'tables' => 2, 'rows' => 5, 'backupCreatedAt' => '2026-10-01T00:00:00+00:00'];
		$restorer = $this->createMock(AppRestorer::class);
		$restorer->expects($this->once())->method('restore')->with('dossiq')->willReturn($restored);

		$response = $this->restoreController($restorer, true)->restoreApp('dossiq');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($restored, $response->getData());
	}

	public function testRestoreAppWithoutABackupIsRefusedAndPointsToARollback(): void {
		$restorer = $this->createMock(AppRestorer::class);
		$restorer->method('restore')->willThrowException(new NoBackupException('There is no backup to restore dossiq from. Roll back to an earlier version instead.'));

		$response = $this->restoreController($restorer, true)->restoreApp('dossiq');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['message' => 'There is no backup to restore dossiq from. Roll back to an earlier version instead.', 'code' => 'no_backup'], $response->getData());
	}

	public function testRestoreAppRefusesANonAdminBeforeRestoring(): void {
		$restorer = $this->createMock(AppRestorer::class);
		$restorer->expects($this->never())->method('restore');

		$response = $this->restoreController($restorer, false)->restoreApp('dossiq');

		$this->assertSame(403, $response->getStatus());
	}

	public function testRestoreAppReportsAFailedRestore(): void {
		$restorer = $this->createMock(AppRestorer::class);
		$restorer->method('restore')->willThrowException(new \RuntimeException('Could not unpack the app folder backup.'));

		$response = $this->restoreController($restorer, true)->restoreApp('dossiq');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'The restore failed: Could not unpack the app folder backup.'], $response->getData());
	}

	public function testTheRestoreRouteRequiresPasswordConfirmation(): void {
		$method = new ReflectionMethod(ApiController::class, 'restoreApp');
		$attributes = array_map(static fn ($attribute): string => $attribute->getName(), $method->getAttributes());

		$this->assertContains(\OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired::class, $attributes);
	}

	private function restoreController(AppRestorer $restorer, bool $isAdmin): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin')->willReturn($isAdmin);

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $this->createMock(IRequest::class),
			'groupManager' => $groupManager,
			'userSession' => $session,
			'serverVersion' => $this->fakeServerVersion(),
			'timeFactory' => $this->fakeTimeFactory(),
			'restorer' => $restorer,
			'connectionReports' => null,
		]);
	}

	public function testSetPolicyRejectsAnOutOfRangeMinimumAge(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'level' => 'patch',
				'minAgeDays' => '91',
				default => $default,
			}
		);

		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->expects($this->never())->method('set');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', $policyStore)->setPolicy('openregister');

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('minAgeDays', $response->getData()['message']);
	}

	public function testSetPolicyStoresTheMinimumAge(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'level' => 'minor',
				'minAgeDays' => '14',
				default => $default,
			}
		);

		$policyStore = $this->createMock(PolicyStore::class);
		$policyStore->expects($this->once())->method('set')
			->with('openregister', $this->callback(static fn ($policy): bool => $policy->minAgeDays === 14));

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', $policyStore)->setPolicy('openregister');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(14, $response->getData()['policy']['minAgeDays']);
	}

	public function testUpdateAutoUpdateSettingsRejectsAnOutOfRangeMinimumAge(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'minAgeDays' => '-1',
				default => $default,
			}
		);

		$settingsStore = $this->createMock(AutoUpdateSettingsStore::class);
		$settingsStore->expects($this->never())->method('setMinAgeDays');
		$settingsStore->expects($this->never())->method('setEnabled');

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', null, $settingsStore)->updateAutoUpdateSettings('1');

		$this->assertSame(400, $response->getStatus());
	}

	public function testUpdateAutoUpdateSettingsStoresTheMinimumAge(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'minAgeDays' => 7,
				default => $default,
			}
		);

		$settingsStore = $this->settingsStoreMock();
		$settingsStore->expects($this->once())->method('setMinAgeDays')->with(7);
		$settingsStore->method('getMinAgeDays')->willReturn(7);

		$installer = $this->createMock(InstallerService::class);
		$response = $this->buildAdminController($installer, $request, null, null, null, 'admin', null, $settingsStore)->updateAutoUpdateSettings();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(7, $response->getData()['autoUpdateMinAgeDays']);
	}
}
