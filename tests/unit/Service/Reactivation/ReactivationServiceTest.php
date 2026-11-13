<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Reactivation;

use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Audit\ExternalChangeRecorder;
use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Reactivation\ReactivationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\Service\Reactivation\ReactivationService
 * @uses \OCA\Versioniq\Service\Audit\SeenStateStore
 * @uses \OCA\Versioniq\Service\AdminNotice
 * @uses \OCA\Versioniq\Service\AdminNotificationServices
 */
final class ReactivationServiceTest extends TestCase {
	private string $stored = '';
	private bool $automatic = false;
	/** @var list<array{subject: string, parameters: array<string, mixed>, user: string}> */
	private array $notified = [];
	private ExternalChangeRecorder&MockObject $recorder;
	private IAppManager&MockObject $appManager;

	protected function setUp(): void {
		$this->recorder = $this->createMock(ExternalChangeRecorder::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->stored = (string)json_encode(['serverVersion' => '33.0.2', 'apps' => ['forms' => ['version' => '4.3.0', 'enabled' => true]], 'disabledByUpdate' => (object)[]]);
	}

	/**
	 * @param array<string, mixed>|null $availability
	 */
	private function service(string $server, string $installed, bool $compatible, ?array $availability = null): ReactivationService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored = $value;

			return true;
		});
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false): bool => $key === 'reactivate.auto' ? $this->automatic : $default);

		$this->appManager->method('getAppVersion')->willReturn($installed);
		$this->appManager->method('getAppInfo')->willReturn(['id' => 'forms', 'version' => $installed]);
		$this->appManager->method('isAppCompatible')->willReturnCallback(static fn (string $serverVersion): bool => $compatible && $serverVersion === $server);

		$provider = $this->createMock(ServerVersionProvider::class);
		$provider->method('current')->willReturn($server);

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$admin]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			$record = ['subject' => '', 'parameters' => [], 'user' => ''];
			$notification->method('setApp')->willReturnSelf();
			$notification->method('setUser')->willReturnCallback(function (string $user) use ($notification, &$record): INotification {
				$record['user'] = $user;

				return $notification;
			});
			$notification->method('setDateTime')->willReturnSelf();
			$notification->method('setObject')->willReturnSelf();
			$notification->method('setLink')->willReturnSelf();
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use ($notification, &$record): INotification {
				$record['subject'] = $subject;
				$record['parameters'] = $parameters;

				return $notification;
			});
			$notification->method('getUser')->willReturnCallback(static function () use (&$record): string {
				return $record['user'];
			});
			$notification->method('getSubjectParameters')->willReturnCallback(static function () use (&$record): array {
				return $record['parameters'];
			});
			$notification->method('getSubject')->willReturnCallback(static function () use (&$record): string {
				return $record['subject'];
			});

			return $notification;
		});
		$manager->method('notify')->willReturnCallback(function (INotification $notification): void {
			$this->notified[] = ['subject' => $notification->getSubject(), 'parameters' => $notification->getSubjectParameters(), 'user' => $notification->getUser()];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-13 12:00:00'));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/settings/admin/versioniq');

		$store = null;
		if ($availability !== null) {
			$store = $this->createMock(AvailabilityResultStore::class);
			$store->method('read')->willReturn(['updates' => $availability, 'checkedAt' => 1]);
		}

		return new ReactivationService(
			new SeenStateStore($config),
			$this->recorder,
			$this->appManager,
			$provider,
			$config,
			new AdminNotificationServices($manager, $groups, $time, $urls),
			$this->createMock(LoggerInterface::class),
			$store,
		);
	}

	private function seen(): array {
		return ['version' => '4.3.0', 'enabled' => true];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function storedState(): array {
		return json_decode($this->stored, true);
	}

	public function testAServerUpgradeLeavingARunnableAppOffNotifiesWhileTheSwitchIsOff(): void {
		$this->appManager->expects($this->never())->method('enableApp');
		$this->recorder->expects($this->never())->method('recordReactivate');

		$outcome = $this->service('34.0.0', '4.3.0', true)->appDisabled('forms', $this->seen(), '33.0.2', 'system', 'found by the hourly check');

		$this->assertSame(ReactivationService::NOTIFIED, $outcome);
		$this->assertSame([[
			'subject' => 'app_left_disabled',
			'parameters' => ['app' => 'forms', 'fromVersion' => 'Nextcloud 33', 'toVersion' => 'Nextcloud 34', 'compatible' => '1', 'serverMajor' => '34', 'compatibleVersion' => ''],
			'user' => 'admin',
		]], $this->notified);
		$this->assertSame(['forms' => ['toVersion' => 'Nextcloud 34', 'compatible' => true, 'compatibleVersion' => null]], $this->storedState()['disabledByUpdate']);
		$this->assertSame(['version' => '4.3.0', 'enabled' => false], $this->storedState()['apps']['forms']);
	}

	public function testWithTheSwitchOnARunnableAppIsEnabledAgainAndRecorded(): void {
		$this->automatic = true;
		$this->appManager->expects($this->once())->method('enableApp')->with('forms');
		$this->recorder->expects($this->once())->method('recordReactivate')->with('forms', '4.3.0', true, 'Enabled again after an update left it disabled.');

		$outcome = $this->service('34.0.0', '4.3.0', true)->appDisabled('forms', $this->seen(), '33.0.2', 'system', 'hourly');

		$this->assertSame(ReactivationService::REACTIVATED, $outcome);
		$this->assertSame([], $this->notified);
		$this->assertSame(['version' => '4.3.0', 'enabled' => true], $this->storedState()['apps']['forms']);
		$this->assertSame([], $this->storedState()['disabledByUpdate']);
	}

	public function testAnAppThatDoesNotRunStaysOffWithTheSwitchOnAndNamesTheVersionThatDoes(): void {
		$this->automatic = true;
		$this->appManager->expects($this->never())->method('enableApp');

		$outcome = $this->service('34.0.0', '4.3.0', false, ['forms' => ['newestCompatibleVersion' => '4.5.0']])
			->appDisabled('forms', $this->seen(), '33.0.2', 'system', 'hourly');

		$this->assertSame(ReactivationService::NOTIFIED, $outcome);
		$this->assertSame('0', $this->notified[0]['parameters']['compatible']);
		$this->assertSame('4.5.0', $this->notified[0]['parameters']['compatibleVersion']);
		$this->assertSame(['toVersion' => 'Nextcloud 34', 'compatible' => false, 'compatibleVersion' => '4.5.0'], $this->storedState()['disabledByUpdate']['forms']);
	}

	public function testADisableWithoutAVersionChangeIsRecordedAndLeftAlone(): void {
		$this->automatic = true;
		$this->appManager->expects($this->never())->method('enableApp');
		$this->recorder->expects($this->once())->method('recordDisable')->with('forms', '4.3.0', 'POST /settings/apps/disable', 'bob');

		$outcome = $this->service('33.0.2', '4.3.0', true)->appDisabled('forms', $this->seen(), '33.0.2', 'bob', 'POST /settings/apps/disable');

		$this->assertSame(ReactivationService::DELIBERATE, $outcome);
		$this->assertSame([], $this->notified);
		$this->assertSame([], $this->storedState()['disabledByUpdate']);
	}

	public function testAFailedEnableIsRecordedAndTheAdminsAreTold(): void {
		$this->automatic = true;
		$this->appManager->method('enableApp')->willThrowException(new \RuntimeException('dependency missing'));
		$this->recorder->expects($this->once())->method('recordReactivate')->with('forms', '4.4.0', false, 'Could not enable the app after an update: dependency missing');

		$outcome = $this->service('33.0.2', '4.4.0', true)->appDisabled('forms', $this->seen(), '33.0.2', 'system', 'hourly');

		$this->assertSame(ReactivationService::NOTIFIED, $outcome);
		// The app's own version changed, so the notice names it.
		$this->assertSame(['4.3.0', '4.4.0'], [$this->notified[0]['parameters']['fromVersion'], $this->notified[0]['parameters']['toVersion']]);
		$this->assertSame('4.4.0', $this->storedState()['disabledByUpdate']['forms']['toVersion']);
	}

	public function testAnAppThatWasNotEnabledIsIgnored(): void {
		$this->recorder->expects($this->never())->method('recordDisable');

		$service = $this->service('34.0.0', '4.3.0', true);

		$this->assertSame(ReactivationService::IGNORED, $service->appDisabled('forms', ['version' => '4.3.0', 'enabled' => false], '33.0.2', 'system', 'hourly'));
		$this->assertSame(ReactivationService::IGNORED, $service->appDisabled('forms', null, '33.0.2', 'system', 'hourly'));
		$this->assertSame([], $this->notified);
	}

	public function testAutomaticReEnablingIsOffByDefault(): void {
		$this->assertFalse($this->service('34.0.0', '4.3.0', true)->isAutomatic());
	}
}
