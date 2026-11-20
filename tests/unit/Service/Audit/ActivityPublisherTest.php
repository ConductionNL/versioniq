<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Audit;

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Audit\ActivityPublisher;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Version changes reach every admin's activity stream (audit-attribution-and-forwarding
 * task 3.2): one event per admin for a successful install, rollback, pin, unpin or
 * policy change, none for anything else, and a failing activity manager never
 * reaches the operation the row records.
 *
 * @covers \OCA\Versioniq\Service\Audit\ActivityPublisher
 * @covers \OCA\Versioniq\Service\Audit\AuditLogger
 * @uses \OCA\Versioniq\Db\AuditEntry
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @uses \OCA\Versioniq\Service\Audit\AuditOrigin
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-audit-rows-reach-the-platforms-audit-log-and-activity-stream
 */
final class ActivityPublisherTest extends TestCase {
	/** @var list<array<string, mixed>> */
	private array $published = [];

	/** @var array<int, array<string, mixed>> What each generated event was given, by object id. */
	private array $fields = [];

	private function entry(string $operation, ?string $from, ?string $to, string $status = 'success', ?string $message = null): AuditEntry {
		$entry = new AuditEntry();
		$entry->setActorUid('alice');
		$entry->setAppId('openregister');
		$entry->setOperation($operation);
		$entry->setFromVersion($from);
		$entry->setToVersion($to);
		$entry->setStatus($status);
		$entry->setMessage($message);

		return $entry;
	}

	private function manager(bool $fails = false): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('generateEvent')->willReturnCallback(function (): IEvent {
			$event = $this->createMock(IEvent::class);
			$id = spl_object_id($event);
			$this->fields[$id] = [];
			$fields = &$this->fields[$id];
			foreach (['setApp', 'setType', 'setAffectedUser', 'setAuthor', 'setTimestamp'] as $setter) {
				$event->method($setter)->willReturnCallback(function (mixed $value) use (&$fields, $setter, $event): IEvent {
					$fields[$setter] = $value;

					return $event;
				});
			}
			$event->method('setObject')->willReturnCallback(function (string $type, string|int $id, string $name = '') use (&$fields, $event): IEvent {
				$fields['setObject'] = [$type, $id, $name];

				return $event;
			});
			$event->method('setSubject')->willReturnCallback(function (string $subject, array $params = []) use (&$fields, $event): IEvent {
				$fields['setSubject'] = [$subject, $params];

				return $event;
			});
			$event->method('getApp')->willReturnCallback(static function () use (&$fields): string {
				return (string)($fields['setApp'] ?? '');
			});
			return $event;
		});
		if ($fails) {
			$manager->method('publish')->willThrowException(new \RuntimeException('activity app broken'));
		} else {
			$manager->method('publish')->willReturnCallback(function (IEvent $event): void {
				$this->published[] = $this->fields[spl_object_id($event)];
			});
		}

		return $manager;
	}

	private function adminGroup(string ...$uids): IGroupManager {
		$users = array_map(function (string $uid): IUser {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		}, $uids);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		return $groups;
	}

	private function time(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1794650400);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T09:00:00+00:00'));

		return $time;
	}

	private function publisher(?IManager $manager = null, ?LoggerInterface $log = null): ActivityPublisher {
		return new ActivityPublisher($manager ?? $this->manager(), $this->adminGroup('alice', 'bob', 'alice'), $this->time(), $log ?? $this->createMock(LoggerInterface::class));
	}

	public function testAPinReachesEachAdminOnce(): void {
		$this->publisher()->publish($this->entry('pin', null, '2.3.0'));

		self::assertCount(2, $this->published);
		self::assertSame(['alice', 'bob'], array_column($this->published, 'setAffectedUser'));
		self::assertSame([
			'setApp' => 'versioniq',
			'setType' => 'versioniq_version_change',
			'setAffectedUser' => 'bob',
			'setAuthor' => 'alice',
			'setTimestamp' => 1794650400,
			'setObject' => ['app', 'openregister', 'openregister'],
			'setSubject' => ['pin', ['actor' => 'alice', 'app' => 'openregister', 'from' => '', 'to' => '2.3.0', 'level' => '']],
		], $this->published[1]);
	}

	/**
	 * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string, 4: ?string}>
	 */
	public static function subjects(): array {
		return [
			'an update' => ['install', '1.0.0', '1.1.0', null, 'install'],
			'a rollback' => ['install', '1.1.0', '1.0.0', null, 'rollback'],
			'a first install' => ['install', null, '1.0.0', null, 'install'],
			'an unpin' => ['unpin', '2.3.0', null, null, 'unpin'],
			'a policy set' => ['policy_set', null, null, 'level: patch -> minor', 'policy_set'],
			'a policy cleared' => ['policy_clear', null, null, 'level: minor -> none', 'policy_clear'],
			'a source binding' => ['bind_source', null, null, null, null],
			'a settings change' => ['auto_update_settings', null, null, 'enabled: false -> true', null],
		];
	}

	/**
	 * @dataProvider subjects
	 */
	public function testEachOperationHasItsSubject(string $operation, ?string $from, ?string $to, ?string $message, ?string $subject): void {
		self::assertSame($subject, ActivityPublisher::subjectOf($this->entry($operation, $from, $to, 'success', $message)));
	}

	public function testAPolicySetCarriesTheNewLevel(): void {
		$this->publisher()->publish($this->entry('policy_set', null, null, 'success', 'level: patch -> minor'));

		self::assertSame('minor', $this->published[0]['setSubject'][1]['level']);
	}

	public function testAFailedOperationIsNotPublished(): void {
		$this->publisher()->publish($this->entry('install', '1.0.0', '1.1.0', 'failure'));

		self::assertSame([], $this->published);
	}

	public function testAFailingActivityManagerIsLoggedAndNeverReachesTheOperation(): void {
		$log = $this->createMock(LoggerInterface::class);
		$log->expects(self::once())->method('warning');
		$inserted = [];
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('insert')->willReturnCallback(static function (AuditEntry $entry) use (&$inserted): AuditEntry {
			$inserted[] = $entry;

			return $entry;
		});

		$auditLogger = new AuditLogger($mapper, $this->time(), $log, null, $this->publisher($this->manager(fails: true), $log));
		$auditLogger->record(new AuditSubject('alice', 'openregister', 'install', 'web'), new AuditedVersions('1.0.0', '1.1.0', null), AuditLogger::STATUS_SUCCESS);

		self::assertCount(1, $inserted);
		self::assertSame('1.1.0', $inserted[0]->getToVersion());
	}

	public function testTheAuditLoggerPublishesWhatItRecorded(): void {
		$auditLogger = new AuditLogger($this->createMock(AuditEntryMapper::class), $this->time(), $this->createMock(LoggerInterface::class), null, $this->publisher());
		$auditLogger->record(new AuditSubject('alice', 'openregister', 'install', 'web'), new AuditedVersions('2.3.0', '2.2.0', null), AuditLogger::STATUS_SUCCESS);

		self::assertSame(['rollback', 'rollback'], array_map(static fn (array $fields): string => $fields['setSubject'][0], $this->published));
	}

	public function testARowThatWasNotWrittenIsNotPublished(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('insert')->willThrowException(new \RuntimeException('table missing'));

		(new AuditLogger($mapper, $this->time(), $this->createMock(LoggerInterface::class), null, $this->publisher()))
			->record(new AuditSubject('alice', 'openregister', 'pin', 'web'), new AuditedVersions(null, '2.3.0', null), AuditLogger::STATUS_SUCCESS);

		self::assertSame([], $this->published);
	}
}
