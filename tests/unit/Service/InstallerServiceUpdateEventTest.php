<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\Events\AppUpdateEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Versioniq#478: an update installed through Versioniq never raised the
 * platform's AppUpdateEvent, so the app's users were not told and no other
 * listener heard of it.
 *
 * @spec openspec/changes/releases-notes-reach/tasks.md#task-1.1
 */
final class InstallerServiceUpdateEventTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private SourceRegistry&MockObject $sourceRegistry;
	private SelectedReleaseInstallerService&MockObject $signedInstaller;
	private EnvironmentCheck&MockObject $environmentCheck;
	private PinStore&MockObject $pinStore;
	private IEventDispatcher&MockObject $dispatcher;
	/** @var list<Event> */
	private array $dispatched = [];
	private bool $notifySwitch = true;
	private string $installedBefore = '1.0.0';

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->sourceRegistry = $this->createMock(SourceRegistry::class);
		$this->signedInstaller = $this->createMock(SelectedReleaseInstallerService::class);
		$this->environmentCheck = $this->createMock(EnvironmentCheck::class);
		$this->pinStore = $this->createMock(PinStore::class);

		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->dispatched[] = $event;
		});

		$this->appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => ($app === Application::APP_ID && $key === InstallerService::KEY_NOTIFY_USERS_ON_UPDATE) ? $this->notifySwitch : $default
		);
		$this->appManager->method('getAlwaysEnabledApps')->willReturn([]);
		$this->appManager->method('getAppVersion')->willReturnCallback(fn (): string => $this->installedBefore);
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->config->method('getSystemValueBool')->willReturn(true);

		$source = $this->createMock(SourceInterface::class);
		$source->method('getInstallerKind')->willReturn(SourceInterface::INSTALLER_SIGNED);
		$source->method('resolveRelease')->willReturn(['download' => 'https://example/app.tar.gz', 'version' => '2.0.0']);
		$this->sourceRegistry->method('get')->willReturn($source);
		$this->signedInstaller->method('getDebugLog')->willReturn([]);
		$this->signedInstaller->method('installFromSelectedRelease')->willReturn(['status' => 'installed']);
	}

	private function service(): InstallerService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-09-28T00:00:00+00:00'));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		return new InstallerService(
			$this->appManager,
			$this->config,
			$this->appConfig,
			$this->sourceRegistry,
			$this->createMock(SourceBindingStore::class),
			$this->createMock(TrustedSourceList::class),
			$this->signedInstaller,
			$this->createMock(ExternalReleaseInstallerService::class),
			new FailureClassifier($factory),
			$this->environmentCheck,
			$this->pinStore,
			$userSession,
			$timeFactory,
			$this->createMock(LkgStore::class),
			$this->createMock(ArtifactCache::class),
			$this->dispatcher,
		);
	}

	/**
	 * @return list<AppUpdateEvent>
	 */
	private function updateEvents(): array {
		return array_values(array_filter($this->dispatched, static fn (Event $e): bool => $e instanceof AppUpdateEvent));
	}

	private function resultingVersion(string $version): void {
		$this->appManager->method('getAppInfoByPath')->willReturn(['version' => $version]);
	}

	public function testAnUpgradeDispatchesOneAppUpdateEvent(): void {
		$this->resultingVersion('2.0.0');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false, null, null, false, false, false, false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		$events = $this->updateEvents();
		self::assertCount(1, $events);
		self::assertSame('someapp', $events[0]->getAppId());
	}

	public function testADryRunDispatchesNothing(): void {
		$this->resultingVersion('1.0.0');

		$this->service()->installAppVersion('someapp', '2.0.0', false, null, null, false, false, false, true);

		self::assertSame([], $this->updateEvents());
	}

	public function testAReinstallOfTheSameVersionDispatchesNothing(): void {
		// A drifted pin is re-installed at the version already on disk.
		$this->pinStore->method('get')->willReturn(new Pin('1.0.0', 'alice', '2026-01-01T00:00:00+00:00', null, '0.9.0', '2026-02-01T00:00:00+00:00'));
		$this->resultingVersion('1.0.0');

		$this->service()->installAppVersion('someapp', '1.0.0', false, null, null, false, false, false, false);

		self::assertSame([], $this->updateEvents());
	}

	public function testAFreshInstallDispatchesNothing(): void {
		$this->installedBefore = '';
		$this->resultingVersion('2.0.0');

		$this->service()->installAppVersion('someapp', '2.0.0', false, null, null, false, false, false, false);

		self::assertSame([], $this->updateEvents());
	}

	public function testTheSwitchOffDispatchesNothing(): void {
		$this->notifySwitch = false;
		$this->resultingVersion('2.0.0');

		$this->service()->installAppVersion('someapp', '2.0.0', false, null, null, false, false, false, false);

		self::assertSame([], $this->updateEvents());
	}

	public function testTheEventIsDispatchedAfterTheRepinSoItDoesNotReadAsDrift(): void {
		$this->pinStore->method('get')->willReturn(new Pin('1.0.0', 'alice', '2026-01-01T00:00:00+00:00'));
		$order = [];
		$this->pinStore->method('set')->willReturnCallback(function () use (&$order): void {
			$order[] = 'pin';
		});
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event) use (&$order): void {
			$order[] = $event instanceof AppUpdateEvent ? 'event' : 'other';
		});
		$this->resultingVersion('2.0.0');

		$this->service()->installAppVersion('someapp', '2.0.0', false, null, InstallerService::OVERRIDE_PIN_REPIN, false, false, false, false);

		self::assertSame(['pin', 'event'], $order);
	}
}
