<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
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

final class AutoUpdateNotifierTest extends TestCase {
	use BuildsByParameterName;

	private function timeFactory(): ITimeFactory {
		$factory = $this->createMock(ITimeFactory::class);
		$factory->method('getDateTime')->willReturn(new \DateTime('2026-07-23T02:00:00+00:00'));

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

	public function testNotifySuccessNotifiesEveryAdminWithBothVersions(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		$notification->expects($this->exactly(2))
			->method('setSubject')
			->with('auto_update_success', [
				'app' => 'openregister',
				'fromVersion' => '2.3.0',
				'toVersion' => '2.3.4',
			])
			->willReturnSelf();

		$notificationManager = $this->createMock(IManager::class);
		$notificationManager->method('createNotification')->willReturn($notification);
		$notificationManager->expects($this->exactly(2))->method('notify')->with($notification);

		$notifier = $this->buildByName(AutoUpdateNotifier::class, [
			'notificationManager' => $notificationManager,
			'groupManager' => $this->groupManagerWithAdmins(['admin', 'bob']),
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$notifier->notifySuccess('openregister', '2.3.0', '2.3.4');
	}

	public function testNotifyPlannedReachesTheAdminsAndTheNoticeGroupEachOnce(): void {
		$users = fn (array $uids): array => array_map(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		}, $uids);
		$admins = $this->createMock(IGroup::class);
		$admins->method('getUsers')->willReturn($users(['admin', 'bob']));
		$notice = $this->createMock(IGroup::class);
		// bob is in both groups and is notified once.
		$notice->method('getUsers')->willReturn($users(['bob', 'fiona']));
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnMap([['admin', $admins], ['functioneel-beheer', $notice]]);

		$recipients = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnCallback(function (string $uid) use (&$recipients, &$notification) {
			$recipients[] = $uid;

			return $notification;
		});
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		$notification->method('setObject')->with('auto_update_planned', 'forms:4.4.0')->willReturnSelf();
		$notification->method('setSubject')->with('auto_update_planned', [
			'app' => 'forms',
			'fromVersion' => '4.3.0',
			'toVersion' => '4.4.0',
			'plannedFor' => '2026-10-16T01:00:00+00:00',
			'releaseNotes' => 'Adds conditional questions.',
		])->willReturnSelf();
		$notificationManager = $this->createMock(IManager::class);
		$notificationManager->method('createNotification')->willReturn($notification);
		$notificationManager->expects($this->exactly(3))->method('notify');

		$notifier = $this->buildByName(AutoUpdateNotifier::class, [
			'notificationManager' => $notificationManager,
			'groupManager' => $groupManager,
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$notifier->notifyPlanned(new ProposedUpdate('forms', '4.3.0', '4.4.0', 'minor', ProposedUpdate::STATUS_IN_NOTICE, '2026-10-01T10:00:00+00:00', '2026-10-16T01:00:00+00:00', '2026-10-01T10:00:00+00:00', 'Adds conditional questions.'), 'functioneel-beheer');

		$this->assertSame(['admin', 'bob', 'fiona'], $recipients);
	}

	public function testNotifyFailureCarriesTheClassifiedHint(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();
		$notification->expects($this->once())
			->method('setSubject')
			->with('auto_update_failure', [
				'app' => 'openregister',
				'targetVersion' => '2.3.4',
				'category' => 'checksum_mismatch',
				'hint' => 'The downloaded archive failed its integrity check.',
			])
			->willReturnSelf();

		$notificationManager = $this->createMock(IManager::class);
		$notificationManager->method('createNotification')->willReturn($notification);
		$notificationManager->expects($this->once())->method('notify')->with($notification);

		$notifier = $this->buildByName(AutoUpdateNotifier::class, [
			'notificationManager' => $notificationManager,
			'groupManager' => $this->groupManagerWithAdmins(['admin']),
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$notifier->notifyFailure('openregister', '2.3.4', 'checksum_mismatch', 'The downloaded archive failed its integrity check.');
	}

	public function testNotifyFailureDoesNotThrowWhenNotifyManagerFails(): void {
		$notificationManager = $this->createMock(IManager::class);
		$notificationManager->method('createNotification')->willThrowException(new \RuntimeException('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$notifier = $this->buildByName(AutoUpdateNotifier::class, [
			'notificationManager' => $notificationManager,
			'groupManager' => $this->groupManagerWithAdmins(['admin']),
			'timeFactory' => $this->timeFactory(),
			'urlGenerator' => $this->urlGenerator(),
			'logger' => $logger,
		]);

		$notifier->notifyFailure('openregister', '2.3.4', 'unknown', '');
	}
}
