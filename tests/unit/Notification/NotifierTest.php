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
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {
	private function l10nFactory(): IFactory {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, is_array($parameters) ? $parameters : [$parameters])
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string => vsprintf(
				$count === 1 ? $singular : $plural,
				$parameters
			)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return $factory;
	}

	private function notification(string $subject, array $parameters): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->expects($this->once())->method('setParsedSubject')->willReturnSelf();
		$notification->expects($this->once())->method('setParsedMessage')->willReturnSelf();

		return $notification;
	}

	public function testPatExpiringIsParsed(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->notification('pat_expiring', [
			'label' => 'conduction-bot',
			'forge' => 'github',
			'daysRemaining' => 5,
		]);

		$result = $notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	public function testPatExpiredIsParsed(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->notification('pat_expired', [
			'label' => 'conduction-bot',
			'forge' => 'github',
		]);

		$result = $notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	public function testPinDriftIsParsed(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->notification('pin_drift', [
			'app' => 'openregister',
			'pinnedVersion' => '2.3.0',
			'observedVersion' => '2.5.0',
		]);

		$result = $notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	public function testAutoUpdateSuccessIsParsed(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->notification('auto_update_success', [
			'app' => 'openregister',
			'fromVersion' => '2.3.0',
			'toVersion' => '2.3.4',
		]);

		$result = $notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	public function testAutoUpdateFailureIsParsed(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->notification('auto_update_failure', [
			'app' => 'openregister',
			'targetVersion' => '2.3.4',
			'category' => 'checksum_mismatch',
			'hint' => 'The downloaded archive failed its integrity check.',
		]);

		$result = $notifier->prepare($notification, 'en');

		$this->assertSame($notification, $result);
	}

	public function testUnknownSubjectThrows(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('something_else');

		$this->expectException(UnknownNotificationException::class);
		$notifier->prepare($notification, 'en');
	}

	public function testWrongAppThrows(): void {
		$notifier = new Notifier($this->l10nFactory());

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('some_other_app');

		$this->expectException(UnknownNotificationException::class);
		$notifier->prepare($notification, 'en');
	}

	public function testAdvisoryDigestIsParsedWithItsCounts(): void {
		$notifier = new Notifier($this->l10nFactory());

		$parsedMessage = null;
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('advisory_digest');
		$notification->method('getSubjectParameters')->willReturn(['apps' => 3, 'advisories' => 5]);
		$notification->expects($this->once())->method('setParsedSubject')->willReturnSelf();
		$notification->expects($this->once())->method('setParsedMessage')->willReturnCallback(
			function (string $message) use (&$parsedMessage, $notification): INotification {
				$parsedMessage = $message;
				return $notification;
			}
		);

		$notifier->prepare($notification, 'en');

		$this->assertIsString($parsedMessage);
		$this->assertStringContainsString('5', $parsedMessage);
		$this->assertStringContainsString('3', $parsedMessage);
	}

	/**
	 * Every subject the app sends must render. A subject without a branch is
	 * stored but skipped by the bell every time, which is how the weekly
	 * advisory digest went unseen (#428). The subjects are read from lib/, so
	 * a new one without a branch fails here.
	 *
	 * @return array<string, array{string}>
	 */
	public static function sentSubjects(): array {
		$subjects = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../lib', \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
				continue;
			}
			$code = (string)file_get_contents($file->getPathname());
			preg_match_all("/->setSubject\\(\\s*'([a-z_]+)'/", $code, $direct);
			preg_match_all("/->withSubject\\(\\s*'([a-z_]+)'/", $code, $viaNotice);
			preg_match_all("/->notice\\(\\s*'([a-z_]+)',\\s*\\[/", $code, $viaHelper);
			foreach ([...$direct[1], ...$viaNotice[1], ...$viaHelper[1]] as $subject) {
				$subjects[$subject] = [$subject];
			}
		}
		ksort($subjects);

		return $subjects;
	}

	public function testTheSubjectScanFindsTheKnownSenders(): void {
		$found = array_keys(self::sentSubjects());
		foreach (['advisory_digest', 'auto_update_failure', 'auto_update_success', 'pat_expired', 'pat_expiring', 'pin_drift', 'pinned_to_vulnerable'] as $expected) {
			$this->assertContains($expected, $found);
		}
	}

	#[DataProvider('sentSubjects')]
	public function testEverySentSubjectRenders(string $subject): void {
		$notifier = new Notifier($this->l10nFactory());

		$result = $notifier->prepare($this->notification($subject, []), 'en');

		$this->assertInstanceOf(INotification::class, $result);
	}

	/**
	 * Prepares one `app_left_disabled` notification and returns its parsed subject and message.
	 *
	 * @param array<string, string> $parameters
	 * @return array{0: string, 1: string}
	 */
	private function leftDisabled(array $parameters): array {
		$parsed = ['', ''];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('app_left_disabled');
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $text) use ($notification, &$parsed): INotification {
			$parsed[0] = $text;

			return $notification;
		});
		$notification->method('setParsedMessage')->willReturnCallback(function (string $text) use ($notification, &$parsed): INotification {
			$parsed[1] = $text;

			return $notification;
		});

		(new Notifier($this->l10nFactory()))->prepare($notification, 'en');

		return $parsed;
	}

	/**
	 * @spec openspec/changes/audit-external-changes
	 */
	public function testAnAppLeftDisabledThatRunsSaysToEnableIt(): void {
		$this->assertSame(
			['An update left an app disabled', 'forms was left disabled by the update from Nextcloud 33 to Nextcloud 34. It runs on this server: enable it.'],
			$this->leftDisabled(['app' => 'forms', 'fromVersion' => 'Nextcloud 33', 'toVersion' => 'Nextcloud 34', 'compatible' => '1', 'serverMajor' => '34', 'compatibleVersion' => '']),
		);
	}

	/**
	 * @spec openspec/changes/audit-external-changes
	 */
	public function testAnAppLeftDisabledThatDoesNotRunNamesTheServerAndTheVersionThatDoes(): void {
		$this->assertSame(
			'hermiq was left disabled by the update from Nextcloud 33 to Nextcloud 34. It does not run on Nextcloud 34.',
			$this->leftDisabled(['app' => 'hermiq', 'fromVersion' => 'Nextcloud 33', 'toVersion' => 'Nextcloud 34', 'compatible' => '0', 'serverMajor' => '34', 'compatibleVersion' => ''])[1],
		);
		$this->assertSame(
			'hermiq was left disabled by the update from Nextcloud 33 to Nextcloud 34. It does not run on Nextcloud 34; version 1.5.0 does.',
			$this->leftDisabled(['app' => 'hermiq', 'fromVersion' => 'Nextcloud 33', 'toVersion' => 'Nextcloud 34', 'compatible' => '0', 'serverMajor' => '34', 'compatibleVersion' => '1.5.0'])[1],
		);
	}
}
