<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Apps tab list: enabled apps, installed-but-disabled apps and apps bound
 * to a source but not installed yet, each with its state (issue #433).
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class InstallerServiceAppListTest extends TestCase {
	use BuildsByParameterName;

	private IAppManager&MockObject $appManager;
	private SourceBindingStore&MockObject $bindingStore;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->bindingStore = $this->createMock(SourceBindingStore::class);

		$this->appManager->method('getAlwaysEnabledApps')->willReturn([]);
		$this->appManager->method('getEnabledApps')->willReturn(['versioniq', 'deck']);
		// Nextcloud's own record: every installed app with its installed
		// version, enabled or not. `calendar` is installed and disabled.
		$this->appManager->method('getAppInstalledVersions')->willReturnMap([
			[false, ['versioniq' => '1.0.0', 'deck' => '1.14.0', 'calendar' => '5.0.1']],
			[true, ['versioniq' => '1.0.0', 'deck' => '1.14.0']],
		]);
		$this->appManager->method('getAppVersion')->willReturnMap([
			['deck', false, '1.14.0'],
			['calendar', false, '5.0.1'],
			['hermiq', false, ''],
		]);
		$this->appManager->method('getAppPath')->willThrowException(new \OCP\App\AppPathNotFoundException('no path'));

		// `hermiq` is bound to a forge source but not installed.
		$this->bindingStore->method('listBoundAppIds')->willReturn(['hermiq', 'deck']);
		$this->bindingStore->method('get')->willReturnCallback(
			static fn (string $appId): ?SourceBinding => $appId === 'hermiq' ? SourceBinding::github('ConductionNL', 'hermiq') : null,
		);
	}

	private function service(): InstallerService {
		return $this->buildByName(InstallerService::class, [
			'appManager' => $this->appManager,
			'config' => $this->createMock(IConfig::class),
			'appConfig' => $this->createMock(IAppConfig::class),
			'sourceRegistry' => $this->createMock(SourceRegistry::class),
			'bindingStore' => $this->bindingStore,
			'trustedSources' => $this->createMock(TrustedSourceList::class),
			'signedInstaller' => $this->createMock(SelectedReleaseInstallerService::class),
			'externalInstaller' => $this->createMock(ExternalReleaseInstallerService::class),
			'failureClassifier' => new FailureClassifier($this->createMock(IFactory::class)),
			'environmentCheck' => $this->createMock(EnvironmentCheck::class),
			'pinStore' => $this->createMock(PinStore::class),
			'userSession' => $this->createMock(IUserSession::class),
			'timeFactory' => $this->createMock(ITimeFactory::class),
			'lkgStore' => $this->createMock(LkgStore::class),
			'artifactCache' => $this->createMock(ArtifactCache::class),
			'eventDispatcher' => $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
		]);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function appsById(): array {
		$byId = [];
		foreach ($this->service()->getInstalledApps() as $app) {
			$byId[$app['id']] = $app;
		}

		return $byId;
	}

	public function testListsEnabledDisabledAndBoundButNotInstalledAppsWithTheirState(): void {
		$apps = $this->appsById();

		self::assertSame(['calendar', 'deck', 'hermiq'], array_keys($apps));
		self::assertSame('enabled', $apps['deck']['state']);
		self::assertSame('disabled', $apps['calendar']['state']);
		self::assertSame('notInstalled', $apps['hermiq']['state']);
	}

	public function testDisabledAppKeepsItsInstalledVersionAndANotInstalledAppHasNone(): void {
		$apps = $this->appsById();

		self::assertSame('5.0.1', $apps['calendar']['installedVersion']);
		self::assertNull($apps['hermiq']['installedVersion']);
		self::assertSame('github:ConductionNL/hermiq', $apps['hermiq']['boundSourceId']);
	}

	public function testVersioniqItselfIsNeverListed(): void {
		self::assertArrayNotHasKey('versioniq', $this->appsById());
	}
}
