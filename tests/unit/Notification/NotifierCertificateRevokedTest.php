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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `certificate_revoked` notification names the app and says what a
 * revoked certificate means, in the wording of design D4.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 */
#[CoversClass(Notifier::class)]
final class NotifierCertificateRevokedTest extends TestCase {
	private const MEANING = 'Nextcloud revoked the signing certificate of this app. Nextcloud revokes a certificate to withdraw an app it knows to be compromised or malicious.';

	public static function cases(): array {
		return [
			'left enabled' => ['0', self::MEANING],
			'disabled' => ['1', self::MEANING . ' badapp was disabled automatically.'],
		];
	}

	#[DataProvider('cases')]
	public function testTheSubjectNamesTheAppAndTheMessageSaysWhatItMeans(string $disabled, string $message): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$parsed = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('certificate_revoked');
		$notification->method('getSubjectParameters')->willReturn(['app' => 'badapp', 'serial' => '2A', 'disabled' => $disabled]);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $text) use (&$parsed, $notification): INotification {
			$parsed['subject'] = $text;

			return $notification;
		});
		$notification->method('setParsedMessage')->willReturnCallback(function (string $text) use (&$parsed, $notification): INotification {
			$parsed['message'] = $text;

			return $notification;
		});

		(new Notifier($factory))->prepare($notification, 'en');

		$this->assertSame(['subject' => 'Certificate revoked: badapp', 'message' => $message], $parsed);
	}
}
