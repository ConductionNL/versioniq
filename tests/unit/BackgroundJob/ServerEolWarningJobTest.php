<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

require_once __DIR__ . '/../Service/Lifecycle/InMemoryAppConfig.php';

use OCA\Versioniq\BackgroundJob\JobRuntime;
use OCA\Versioniq\BackgroundJob\ServerEolWarningJob;
use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Lifecycle\ServerEolFeed;
use OCA\Versioniq\Service\Lifecycle\ServerEolWarner;
use OCA\Versioniq\Tests\Unit\Service\Lifecycle\InMemoryAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
 */
#[CoversClass(ServerEolWarningJob::class)]
#[CoversClass(ServerEolWarner::class)]
#[UsesClass(AdminNotice::class)]
#[UsesClass(AdminNotificationServices::class)]
#[UsesClass(JobRuntime::class)]
final class ServerEolWarningJobTest extends TestCase {
	/** @var list<array{user: string, subject: string, parameters: array}> */
	private array $sent = [];

	private InMemoryAppConfig $store;

	private ?string $eolDate = '2027-03-31';

	private string $serverVersion = '33.0.4';

	/**
	 * Run the job once on the given day.
	 */
	private function runOn(string $day): void {
		[$appConfig, $store] = InMemoryAppConfig::for($this, isset($this->store) ? $this->store->values : []);
		$this->store = $store;

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime($day . 'T09:00:00Z'));
		$time->method('getTime')->willReturn((new \DateTime($day . 'T09:00:00Z'))->getTimestamp());

		$feed = $this->createMock(ServerEolFeed::class);
		$feed->method('eolDate')->willReturnCallback(fn (int $major): ?string => $major === 33 ? $this->eolDate : null);
		$version = $this->createMock(ServerVersionProvider::class);
		$version->method('current')->willReturn($this->serverVersion);

		$warner = new ServerEolWarner($feed, $version, $appConfig, $this->adminServices($time), new NullLogger());
		$job = new ServerEolWarningJob(new JobRuntime($time, new NullLogger()), $warner);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	private function adminServices(ITimeFactory $time): AdminNotificationServices {
		$users = [];
		foreach (['admin', 'beheer'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$record = ['user' => '', 'subject' => '', 'parameters' => []];
			$notification = $this->createMock(INotification::class);
			foreach (['setApp', 'setDateTime', 'setObject', 'setLink'] as $method) {
				$notification->method($method)->willReturnSelf();
			}
			$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification, &$record): INotification {
				$record['user'] = $uid;

				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use ($notification, &$record): INotification {
				$record['subject'] = $subject;
				$record['parameters'] = $parameters;

				return $notification;
			});
			$notification->method('getUser')->willReturnCallback(static function () use (&$record): string {
				return $record['user'];
			});
			$this->pending[spl_object_id($notification)] = &$record;

			return $notification;
		});
		$manager->method('notify')->willReturnCallback(function (INotification $notification): void {
			$this->sent[] = $this->pending[spl_object_id($notification)];
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example/settings/admin/versioniq');

		return new AdminNotificationServices($manager, $groups, $time, $urls);
	}

	/** @var array<int, array> */
	private array $pending = [];

	public function testEveryAdminIsWarnedOnceAtNinetyDays(): void {
		$this->runOn('2027-01-01');

		$this->assertSame(
			[
				['user' => 'admin', 'subject' => 'server_eol', 'parameters' => ['major' => '33', 'date' => '2027-03-31', 'daysRemaining' => 89]],
				['user' => 'beheer', 'subject' => 'server_eol', 'parameters' => ['major' => '33', 'date' => '2027-03-31', 'daysRemaining' => 89]],
			],
			$this->sent
		);

		$this->runOn('2027-01-02');
		$this->assertCount(2, $this->sent, 'the 90-day warning is not sent again the next day');
	}

	public function testThirtyDaysWarnsOnceMore(): void {
		$this->runOn('2027-01-01');
		$this->runOn('2027-03-01');
		$this->runOn('2027-03-02');

		$this->assertCount(4, $this->sent);
		$this->assertSame(30, $this->sent[3]['parameters']['daysRemaining']);
	}

	public function testNothingIsSentBeforeNinetyDaysOrAfterTheDate(): void {
		$this->runOn('2026-12-30');
		$this->runOn('2027-04-01');

		$this->assertSame([], $this->sent);
	}

	public function testAFeedWithoutADateSendsNothing(): void {
		$this->eolDate = null;
		$this->runOn('2027-03-01');

		$this->assertSame([], $this->sent);
	}

	public function testAMovedDateIsWarnedAgain(): void {
		$this->runOn('2027-01-01');
		$this->eolDate = '2027-06-30';
		$this->runOn('2027-04-01');

		$this->assertCount(4, $this->sent);
		$this->assertSame('2027-06-30', $this->sent[3]['parameters']['date']);
	}
}
