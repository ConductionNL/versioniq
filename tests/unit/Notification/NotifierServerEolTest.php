<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Notification;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Notification\Notifier;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
 */
#[CoversClass(Notifier::class)]
final class NotifierServerEolTest extends TestCase {
	public function testTheServerEolSubjectNamesTheMajorAndTheDate(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string => vsprintf($count === 1 ? $singular : $plural, $parameters)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$parsed = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('server_eol');
		$notification->method('getSubjectParameters')->willReturn(['major' => '33', 'date' => '2027-03-31', 'daysRemaining' => 30]);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $text) use (&$parsed, $notification): INotification {
			$parsed['subject'] = $text;

			return $notification;
		});
		$notification->method('setParsedMessage')->willReturnCallback(function (string $text) use (&$parsed, $notification): INotification {
			$parsed['message'] = $text;

			return $notification;
		});

		(new Notifier($factory))->prepare($notification, 'en');

		$this->assertSame(
			[
				'subject' => 'Nextcloud 33 reaches end of life soon',
				'message' => 'Nextcloud 33 stops receiving updates on 2027-03-31, in 30 days. Plan the upgrade to a maintained version.',
			],
			$parsed
		);
	}
}
