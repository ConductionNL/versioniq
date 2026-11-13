<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

use DateTime;
use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\NotifiesAdmins;
use OCA\Versioniq\Service\ResolvesAdminUids;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit test for the NotifiesAdmins trait, exercised through a minimal host:
 * the notice is linked to the app's admin settings page, one notification is
 * sent per distinct admin, and a failure for one admin is logged and does
 * not stop the others.
 *
 * @covers \OCA\Versioniq\Service\NotifiesAdmins
 * @covers \OCA\Versioniq\Service\ResolvesAdminUids
 * @uses \OCA\Versioniq\Service\AdminNotice
 * @uses \OCA\Versioniq\Service\AdminNotificationServices
 */
final class NotifiesAdminsTest extends TestCase {
	/** @var list<array<array-key, mixed>> */
	private array $calls = [];

	/**
	 * @param list<string>|null $uids admin group members; null: no admin group
	 * @param list<string> $failFor uids whose notify() throws
	 */
	private function host(?array $uids, LoggerInterface $logger, array $failFor = []): object {
		$group = null;
		if ($uids !== null) {
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn(array_map(function (string $uid): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);

				return $user;
			}, $uids));
		}
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $gid) => $gid === 'admin' ? $group : null);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(function (string $route, array $params): string {
			$this->calls[] = ['link', $route, $params];

			return 'https://cloud.example/settings/admin/' . $params['section'];
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:00:00+00:00'));

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$manager->method('notify')->willReturnCallback(function (INotification $notification) use ($failFor): void {
			$user = $notification->getUser();
			if (in_array($user, $failFor, true)) {
				throw new RuntimeException('push failed for ' . $user);
			}
			$this->calls[] = ['notify', $user];
		});

		$admin = new AdminNotificationServices($manager, $groups, $time, $urls);

		return new class($admin, $logger) {
			use NotifiesAdmins;
			use ResolvesAdminUids;

			public function __construct(
				private AdminNotificationServices $admin,
				private LoggerInterface $logger,
			) {
			}

			public function send(AdminNotice $notice): void {
				$this->notifyAdmins($notice);
			}
		};
	}

	/**
	 * A notification double that records its setters and remembers its user.
	 */
	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		$user = '';
		$record = function (string $method) use ($notification): \Closure {
			return function (mixed ...$args) use ($method, $notification): INotification {
				$this->calls[] = array_merge([$method], array_map(
					fn (mixed $arg): mixed => $arg instanceof \DateTimeInterface ? $arg->format(DATE_ATOM) : $arg,
					$args,
				));

				return $notification;
			};
		};
		foreach (['setApp', 'setDateTime', 'setObject', 'setSubject', 'setLink'] as $method) {
			$notification->method($method)->willReturnCallback($record($method));
		}
		$notification->method('setUser')->willReturnCallback(function (string $uid) use (&$user, $notification): INotification {
			$user = $uid;
			$this->calls[] = ['setUser', $uid];

			return $notification;
		});
		$notification->method('getUser')->willReturnCallback(function () use (&$user): string {
			return $user;
		});

		return $notification;
	}

	private function notice(): AdminNotice {
		return (new AdminNotice())
			->withObject('app', 'mail')
			->withSubject('update_failed', ['app' => 'mail'])
			->loggedAs('Failed to notify admin', ['app' => 'mail']);
	}

	public function testEachDistinctAdminGetsOneLinkedNotification(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->host(['bob', 'alice', 'bob'], $logger)->send($this->notice());

		$perUser = static fn (string $uid): array => [
			['setApp', 'versioniq'],
			['setUser', $uid],
			['setDateTime', '2026-11-13T10:00:00+00:00'],
			['setObject', 'app', 'mail'],
			['setSubject', 'update_failed', ['app' => 'mail']],
			['setLink', 'https://cloud.example/settings/admin/versioniq'],
			['notify', $uid],
		];
		$this->assertSame(array_merge(
			[['link', 'settings.AdminSettings.index', ['section' => 'versioniq']]],
			$perUser('bob'),
			$perUser('alice'),
		), $this->calls);
	}

	public function testWithoutAnAdminGroupNothingIsSent(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->host(null, $logger)->send($this->notice());

		$this->assertSame([['link', 'settings.AdminSettings.index', ['section' => 'versioniq']]], $this->calls);
	}

	public function testAFailureForOneAdminIsLoggedAndTheOthersAreStillNotified(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Failed to notify admin',
			['app' => 'mail', 'uid' => 'alice', 'message' => 'push failed for alice'],
		);

		$this->host(['alice', 'carol'], $logger, ['alice'])->send($this->notice());

		$notified = array_values(array_filter($this->calls, fn (array $call): bool => $call[0] === 'notify'));
		$this->assertSame([['notify', 'carol']], $notified);
	}

	public function testAFailureCreatingTheNotificationIsLoggedPerAdmin(): void {
		$groups = $this->createMock(IGroupManager::class);
		$group = $this->createMock(IGroup::class);
		$users = array_map(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		}, ['alice', 'bob']);
		$group->method('getUsers')->willReturn($users);
		$groups->method('get')->willReturn($group);
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willThrowException(new RuntimeException('no manager'));
		$manager->expects($this->never())->method('notify');
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('/link');

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
			$logged[] = [$message, $context];
		});

		$admin = new AdminNotificationServices($manager, $groups, $this->createMock(ITimeFactory::class), $urls);
		$host = new class($admin, $logger) {
			use NotifiesAdmins;
			use ResolvesAdminUids;

			public function __construct(
				private AdminNotificationServices $admin,
				private LoggerInterface $logger,
			) {
			}

			public function send(AdminNotice $notice): void {
				$this->notifyAdmins($notice);
			}
		};
		$host->send($this->notice());

		$this->assertSame([
			['Failed to notify admin', ['app' => 'mail', 'uid' => 'alice', 'message' => 'no manager']],
			['Failed to notify admin', ['app' => 'mail', 'uid' => 'bob', 'message' => 'no manager']],
		], $logged);
	}
}
