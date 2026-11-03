<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Pin;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinDrift;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PinDriftHandlerTest extends TestCase {
	use BuildsByParameterName;

	private function timeFactory(): ITimeFactory {
		$factory = $this->createMock(ITimeFactory::class);
		$factory->method('getDateTime')->willReturn(new \DateTime('2026-06-12T00:00:00+00:00'));

		return $factory;
	}

	private function urlGenerator(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.test/settings/admin/versioniq');

		return $urlGenerator;
	}

	private function groupManagerWithAdmins(array $uids): IGroupManager {
		$users = array_map(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		}, $uids);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('admin')->willReturn($group);

		return $groupManager;
	}

	public function testNoDriftWhenInstalledVersionMatchesThePin(): void {
		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('get')->willReturn(new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00'));
		$pinStore->expects($this->never())->method('markDrift');

		$notificationManager = $this->createMock(IManager::class);
		$notificationManager->expects($this->never())->method('notify');

		$handler = $this->buildByName(PinDriftHandler::class, [
			'pinStore' => $pinStore,
			'notificationManager' => $notificationManager,
			'groupManager' => $this->groupManagerWithAdmins(['admin']),
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$handler->handle('openregister', '2.3.0');
	}

	public function testNothingHappensWhenAppIsNotPinned(): void {
		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('get')->willReturn(null);
		$pinStore->expects($this->never())->method('markDrift');

		$notificationManager = $this->createMock(IManager::class);
		$notificationManager->expects($this->never())->method('notify');

		$handler = $this->buildByName(PinDriftHandler::class, [
			'pinStore' => $pinStore,
			'notificationManager' => $notificationManager,
			'groupManager' => $this->groupManagerWithAdmins(['admin']),
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$handler->handle('openregister', '2.5.0');
	}

	public function testNewDriftNotifiesEveryAdmin(): void {
		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('get')->willReturn(new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00'));
		$pinStore->expects($this->once())
			->method('markDrift')
			->with('openregister', new PinDrift('2.5.0', '2026-06-12T00:00:00+00:00'))
			->willReturn(true);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->expects($this->exactly(2))
			->method('setLink')
			->with('https://example.test/settings/admin/versioniq')
			->willReturnSelf();

		$notificationManager = $this->createMock(IManager::class);
		$notificationManager->method('createNotification')->willReturn($notification);
		$notificationManager->expects($this->exactly(2))->method('notify')->with($notification);

		$handler = $this->buildByName(PinDriftHandler::class, [
			'pinStore' => $pinStore,
			'notificationManager' => $notificationManager,
			'groupManager' => $this->groupManagerWithAdmins(['admin', 'bob']),
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$handler->handle('openregister', '2.5.0');
	}

	public function testAlreadyRecordedDriftDoesNotNotifyAgain(): void {
		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('get')->willReturn((new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00'))->withDrift('2.5.0', '2026-06-11T13:00:00+00:00'));
		$pinStore->method('markDrift')->willReturn(false);

		$notificationManager = $this->createMock(IManager::class);
		$notificationManager->expects($this->never())->method('notify');

		$handler = $this->buildByName(PinDriftHandler::class, [
			'pinStore' => $pinStore,
			'notificationManager' => $notificationManager,
			'groupManager' => $this->groupManagerWithAdmins(['admin']),
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$handler->handle('openregister', '2.5.0');
	}
}
