<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\ReleaseAlert;

require_once __DIR__ . '/ReleaseAlertTestCase.php';

use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseAlertSettings;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseEventLog;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Each scope with a forge app, an App Store app and an app without a source.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
 */
final class ReleaseNotifierTest extends ReleaseAlertTestCase {
	/** @var list<array{0: string, 1: string, 2: array<string, string>}> */
	private array $sent = [];

	private function notifier(string $scope): ReleaseNotifier {
		$this->stored[ReleaseAlertSettings::KEY_SCOPE] = $scope;
		$current = ['user' => '', 'subject' => '', 'parameters' => []];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime', 'setObject', 'setLink'] as $method) {
			$notification->method($method)->willReturnSelf();
		}
		$notification->method('setUser')->willReturnCallback(function (string $uid) use (&$current, $notification) {
			$current['user'] = $uid;

			return $notification;
		});
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use (&$current, $notification) {
			$current['subject'] = $subject;
			$current['parameters'] = $parameters;

			return $notification;
		});
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->method('notify')->willReturnCallback(function () use (&$current): void {
			$this->sent[] = [$current['user'], $current['subject'], $current['parameters']];
		});

		$users = array_map(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		}, ['alice', 'bob']);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T08:00:00+00:00'));
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/settings/admin/versioniq');

		return new ReleaseNotifier(
			new AdminNotificationServices($manager, $groups, $time, $url),
			$this->settings(),
			$this->createMock(LoggerInterface::class),
		);
	}

	/** @return list<array<string, mixed>> */
	private static function events(): array {
		$incompatible = self::event('calendar', '5.2.0', 'appstore');
		$incompatible['compatible'] = false;

		return [self::event('hermiq', '1.5.0'), $incompatible, self::event('legacy', '0.9.0', null)];
	}

	public function testForgeNotifiesEveryAdminOfForgeAppsOnly(): void {
		$this->notifier('forge')->notifyReleases(self::events());

		self::assertSame([
			['alice', 'new_release', ['app' => 'hermiq', 'version' => '1.5.0', 'sourceId' => 'github:ConductionNL/hermiq', 'compatible' => '1']],
			['bob', 'new_release', ['app' => 'hermiq', 'version' => '1.5.0', 'sourceId' => 'github:ConductionNL/hermiq', 'compatible' => '1']],
		], $this->sent);
	}

	public function testAllNotifiesEveryApp(): void {
		$this->notifier('all')->notifyReleases(self::events());

		self::assertCount(6, $this->sent);
		self::assertSame(['app' => 'calendar', 'version' => '5.2.0', 'sourceId' => 'appstore', 'compatible' => '0'], $this->sent[2][2]);
		self::assertSame(['app' => 'legacy', 'version' => '0.9.0', 'sourceId' => '', 'compatible' => '1'], $this->sent[4][2]);
	}

	public function testOffNotifiesNothingButStillLogs(): void {
		$this->notifier('off')->notifyReleases(self::events());

		self::assertSame([], $this->sent);
		self::assertSame(['legacy', 'calendar', 'hermiq'], array_column(json_decode($this->stored[ReleaseEventLog::KEY], true), 'appId'));
	}
}
