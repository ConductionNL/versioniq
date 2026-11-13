<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

use OCA\Versioniq\Service\AdminNotice;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit test for the immutable AdminNotice value object: every wither returns
 * a copy and leaves the original untouched, applyTo() writes object, subject
 * and link onto the notification, and the log context always carries the uid
 * and error message without overriding keys the caller set.
 *
 * @covers \OCA\Versioniq\Service\AdminNotice
 */
final class AdminNoticeTest extends TestCase {
	/**
	 * Records every setter call on a notification double, in order.
	 *
	 * @param list<array{0: string, 1: list<mixed>}> $calls
	 */
	private function recordingNotification(array &$calls): INotification {
		$notification = $this->createMock(INotification::class);
		foreach (['setObject', 'setSubject', 'setLink'] as $method) {
			$notification->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method, &$calls, $notification): INotification {
					$calls[] = [$method, $args];

					return $notification;
				},
			);
		}

		return $notification;
	}

	public function testAFreshNoticeAppliesEmptyValues(): void {
		$calls = [];
		(new AdminNotice())->applyTo($this->recordingNotification($calls));

		$this->assertSame([
			['setObject', ['', '']],
			['setSubject', ['', []]],
			['setLink', ['']],
		], $calls);
		$this->assertSame('', (new AdminNotice())->logMessage());
	}

	public function testApplyToWritesObjectSubjectAndLinkInOrder(): void {
		$calls = [];
		$notice = (new AdminNotice())
			->withObject('app', 'mail')
			->withSubject('update_failed', ['app' => 'mail', 'version' => '4.0.1'])
			->withLink('https://cloud.example/settings/admin/versioniq');

		$notice->applyTo($this->recordingNotification($calls));

		$this->assertSame([
			['setObject', ['app', 'mail']],
			['setSubject', ['update_failed', ['app' => 'mail', 'version' => '4.0.1']]],
			['setLink', ['https://cloud.example/settings/admin/versioniq']],
		], $calls);
	}

	public function testEveryWitherReturnsACopyAndLeavesTheOriginalUnchanged(): void {
		$original = (new AdminNotice())
			->withObject('app', 'calendar')
			->withSubject('drift', ['app' => 'calendar'])
			->withLink('/old')
			->loggedAs('original message', ['a' => 1]);

		$this->assertNotSame($original, $original->withObject('pin', 'x'));
		$this->assertNotSame($original, $original->withSubject('other', []));
		$this->assertNotSame($original, $original->withLink('/new'));
		$this->assertNotSame($original, $original->loggedAs('changed', []));

		$calls = [];
		$original->applyTo($this->recordingNotification($calls));
		$this->assertSame([
			['setObject', ['app', 'calendar']],
			['setSubject', ['drift', ['app' => 'calendar']]],
			['setLink', ['/old']],
		], $calls);
		$this->assertSame('original message', $original->logMessage());
	}

	public function testAWitherOnlyReplacesItsOwnFields(): void {
		$calls = [];
		(new AdminNotice())
			->withObject('app', 'deck')
			->withSubject('success', ['app' => 'deck'])
			->withLink('/first')
			->withLink('/second')
			->applyTo($this->recordingNotification($calls));

		$this->assertSame([
			['setObject', ['app', 'deck']],
			['setSubject', ['success', ['app' => 'deck']]],
			['setLink', ['/second']],
		], $calls);
	}

	public function testLogContextAddsUidAndErrorMessage(): void {
		$notice = (new AdminNotice())->loggedAs('Could not notify admin', ['app' => 'mail']);

		$this->assertSame('Could not notify admin', $notice->logMessage());
		$this->assertSame(
			['app' => 'mail', 'uid' => 'alice', 'message' => 'boom'],
			$notice->logContext('alice', new RuntimeException('boom')),
		);
	}

	public function testLogContextKeepsCallerKeysThatCollideWithUidOrMessage(): void {
		$notice = (new AdminNotice())->loggedAs('m', ['uid' => 'preset', 'message' => 'kept']);

		$this->assertSame(
			['uid' => 'preset', 'message' => 'kept'],
			$notice->logContext('alice', new RuntimeException('boom')),
		);
	}
}
