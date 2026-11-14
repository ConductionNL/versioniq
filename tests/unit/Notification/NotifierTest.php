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
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 */
	public function testNewReleaseNamesTheAppAndVersionAndSaysWhenItDoesNotRunHere(): void {
		$notifier = new Notifier($this->l10nFactory());

		$compatible = $this->createMock(INotification::class);
		$compatible->method('getApp')->willReturn(Application::APP_ID);
		$compatible->method('getSubject')->willReturn('new_release');
		$compatible->method('getSubjectParameters')->willReturn(['app' => 'hermiq', 'version' => '1.5.0', 'sourceId' => 'github:ConductionNL/hermiq', 'compatible' => '1']);
		$compatible->expects($this->once())->method('setParsedSubject')->with('New version of hermiq: 1.5.0')->willReturnSelf();
		$compatible->expects($this->never())->method('setParsedMessage');
		$notifier->prepare($compatible, 'en');

		$incompatible = $this->createMock(INotification::class);
		$incompatible->method('getApp')->willReturn(Application::APP_ID);
		$incompatible->method('getSubject')->willReturn('new_release');
		$incompatible->method('getSubjectParameters')->willReturn(['app' => 'calendar', 'version' => '5.2.0', 'sourceId' => 'appstore', 'compatible' => '0']);
		$incompatible->expects($this->once())->method('setParsedSubject')->with('New version of calendar: 5.2.0')->willReturnSelf();
		$incompatible->expects($this->once())->method('setParsedMessage')->with('It does not run on this server version.')->willReturnSelf();
		$notifier->prepare($incompatible, 'en');
	}
}
