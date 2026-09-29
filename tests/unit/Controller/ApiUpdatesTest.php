<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatValidator;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/updates (inventory-pending-updates D4): admin-only, reads the
 * stored snapshot, never a source.
 *
 * @spec openspec/changes/inventory-pending-updates/specs/pending-updates/spec.md
 */
final class ApiUpdatesTest extends TestCase {
	private function controller(bool $admin, AvailabilityResultStore $store, InstanceSettings $settings, ?InstallerService $installer = null): ApiController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);

		return new ApiController(
			'versioniq',
			$this->createMock(IRequest::class),
			$installer ?? $this->createMock(InstallerService::class),
			$groups,
			$session,
			(new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
			$this->createMock(PatMapper::class),
			$this->createMock(PatManager::class),
			$this->createMock(PatValidator::class),
			$this->createMock(PatDeeplinkBuilder::class),
			$this->createMock(PatExpiryEvaluator::class),
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
			null,
			$this->createMock(AttemptLedger::class),
			$store,
			$settings,
		);
	}

	public function testANonAdminGets403AndNoAppData(): void {
		$store = $this->createMock(AvailabilityResultStore::class);
		$store->expects(self::never())->method('read');

		$response = $this->controller(false, $store, $this->createMock(InstanceSettings::class))->updates();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayNotHasKey('updates', $response->getData());
	}

	public function testAnAdminGetsTheSnapshotItsTimeAndTheLimitWithoutAnySourceCall(): void {
		$snapshot = ['openregister' => ['installedVersion' => '2.3.0', 'newestCompatibleVersion' => '2.4.1', 'linesBehind' => 1]];
		$store = $this->createMock(AvailabilityResultStore::class);
		$store->method('read')->willReturn(['updates' => $snapshot, 'checkedAt' => 1_790_000_000]);
		$settings = $this->createMock(InstanceSettings::class);
		$settings->method('maxLinesBehind')->willReturn(1);
		$installer = $this->createMock(InstallerService::class);
		$installer->expects(self::never())->method('getAppVersions');

		$response = $this->controller(true, $store, $settings, $installer)->updates();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['updates' => $snapshot, 'checkedAt' => 1_790_000_000, 'maxLinesBehind' => 1], $response->getData());
	}
}
