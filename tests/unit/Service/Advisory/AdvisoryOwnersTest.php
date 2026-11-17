<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Advisory\AdvisoryOwners;
use OCA\Versioniq\Service\Advisory\TriageRefusal;
use OCA\Versioniq\Service\Advisory\TriageTarget;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\Service\Advisory\AdvisoryOwners
 * @uses \OCA\Versioniq\Service\AdminNotificationServices
 * @uses \OCA\Versioniq\Service\Advisory\TriageRefusal
 * @uses \OCA\Versioniq\Service\Advisory\TriageTarget
 * @uses \OCA\Versioniq\Service\Advisory\AdvisoryTriageStore
 */
final class AdvisoryOwnersTest extends TestCase {
	/** @var list<list<mixed>> */
	private array $calls = [];

	private function owners(?IManager $manager = null, ?LoggerInterface $logger = null): AdvisoryOwners {
		$members = [];
		foreach (['bob', 'carol', 'dave'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$members[] = $user;
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['alice', 'bob', 'dave'], true));
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'ops');
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => $gid === 'ops' ? $group : null);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->with('settings.AdminSettings.index', ['section' => 'versioniq'])->willReturn('https://cloud/settings/admin/versioniq');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-09-27T10:00:00+00:00'));

		return new AdvisoryOwners(
			new AdminNotificationServices($manager ?? $this->manager(), $groups, $time, $urls),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	private function manager(): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink'] as $setter) {
				$notification->method($setter)->willReturnCallback(function (mixed ...$args) use ($setter, $notification): INotification {
					if ($setter !== 'setDateTime') {
						$this->calls[] = array_merge([$setter], $args);
					}

					return $notification;
				});
			}

			return $notification;
		});

		return $manager;
	}

	public function testParseAcceptsAnAdminUserAnExistingGroupAndEmpty(): void {
		$owners = $this->owners();

		$this->assertSame(['type' => 'user', 'id' => 'bob'], $owners->parse('user:bob'));
		$this->assertSame(['type' => 'group', 'id' => 'ops'], $owners->parse('group:ops'));
		$this->assertNull($owners->parse(''));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unacceptable(): array {
		return [
			'non-admin user' => ['user:carol'],
			'unknown group' => ['group:nobody'],
			'no type' => ['bob'],
			'empty id' => ['user:'],
			'other type' => ['team:ops'],
		];
	}

	/**
	 * @dataProvider unacceptable
	 */
	public function testParseRefusesAnythingElseWith400(string $assignee): void {
		$this->expectException(TriageRefusal::class);
		$this->expectExceptionCode(400);

		$this->owners()->parse($assignee);
	}

	public function testTheOwnersOfAGroupAreItsAdminMembers(): void {
		$owners = $this->owners();

		$this->assertSame(['bob', 'dave'], $owners->owners(['type' => 'group', 'id' => 'ops']));
		$this->assertSame(['alice'], $owners->owners(['type' => 'user', 'id' => 'alice']));
		$this->assertSame([], $owners->owners(['type' => 'group', 'id' => 'gone']));
	}

	public function testNotifyNamesTheAppTheAdvisoryAndWhoAssignedItAndLinksToVersioniq(): void {
		$sent = $this->owners()->notify(['type' => 'user', 'id' => 'bob'], new TriageTarget('openregister', 'GHSA-1', 'alice'));

		$this->assertSame(1, $sent);
		$this->assertSame([
			['setApp', 'versioniq'],
			['setUser', 'bob'],
			['setObject', 'advisory', 'openregister|GHSA-1'],
			['setSubject', 'advisory_assigned', ['app' => 'openregister', 'advisory' => 'GHSA-1', 'by' => 'alice']],
			['setLink', 'https://cloud/settings/admin/versioniq'],
		], $this->calls);
	}

	public function testAFailedDispatchIsLoggedAndTheOtherOwnersAreStillNotified(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->createMock(INotification::class));
		$attempts = [];
		$manager->method('notify')->willReturnCallback(function () use (&$attempts): void {
			$attempts[] = count($attempts);
			if (count($attempts) === 1) {
				throw new \RuntimeException('notifications app is gone');
			}
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('AdvisoryOwners: could not notify an owner', ['user' => 'bob', 'message' => 'notifications app is gone']);

		$sent = $this->owners($manager, $logger)->notify(['type' => 'group', 'id' => 'ops'], new TriageTarget(':server', 'GHSA-s', 'alice'));

		$this->assertSame(1, $sent);
		$this->assertCount(2, $attempts);
	}
}
