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

	public function testAutoUpdatePlannedCarriesThePlannedDateAndTheReleaseNotes(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('auto_update_planned');
		$notification->method('getSubjectParameters')->willReturn([
			'app' => 'forms',
			'fromVersion' => '4.3.0',
			'toVersion' => '4.4.0',
			'plannedFor' => '2026-10-16T01:00:00+00:00',
			'releaseNotes' => 'Adds conditional questions.',
		]);
		$notification->expects($this->once())->method('setParsedSubject')->with('Update planned for forms')->willReturnSelf();
		$notification->expects($this->once())->method('setParsedMessage')->with(
			"forms will be updated from 4.3.0 to 4.4.0 on or after 2026-10-16T01:00:00+00:00.\n\nRelease notes:\nAdds conditional questions."
		)->willReturnSelf();

		(new Notifier($this->l10nFactory()))->prepare($notification, 'en');
	}

	public function testAutoUpdatePlannedWithoutNotesOrDateStillRenders(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('auto_update_planned');
		$notification->method('getSubjectParameters')->willReturn(['app' => 'forms', 'fromVersion' => '4.3.0', 'toVersion' => '4.4.0']);
		$notification->expects($this->once())->method('setParsedSubject')->willReturnSelf();
		$notification->expects($this->once())->method('setParsedMessage')
			->with('forms will be updated from 4.3.0 to 4.4.0 at a coming update window.')->willReturnSelf();

		(new Notifier($this->l10nFactory()))->prepare($notification, 'en');
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

	public function testAGroupRunListsEveryMemberWithItsOutcome(): void {
		$notifier = new Notifier($this->l10nFactory());

		$parsed = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('auto_update_group');
		$notification->method('getSubjectParameters')->willReturn([
			'group' => 'Registers',
			'members' => [
				['appId' => 'openregister', 'version' => '2.3.4', 'outcome' => 'installed'],
				['appId' => 'opencatalogi', 'version' => '1.2.1', 'outcome' => 'failed'],
				['appId' => 'woo', 'version' => '3.0.1', 'outcome' => 'not_attempted'],
			],
		]);
		$notification->expects($this->once())->method('setParsedSubject')->willReturnCallback(
			function (string $subject) use (&$parsed, $notification): INotification {
				$parsed['subject'] = $subject;
				return $notification;
			}
		);
		$notification->expects($this->once())->method('setParsedMessage')->willReturnCallback(
			function (string $message) use (&$parsed, $notification): INotification {
				$parsed['message'] = $message;
				return $notification;
			}
		);

		$notifier->prepare($notification, 'en');

		$this->assertSame([
			'subject' => 'Update group Registers',
			'message' => "openregister: updated to 2.3.4\nopencatalogi: update to 1.2.1 failed\nwoo: not attempted",
		], $parsed);
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
		foreach (['advisory_assigned', 'advisory_digest', 'auto_update_failure', 'auto_update_planned', 'auto_update_success', 'force_lifted', 'pat_expired', 'pat_expiring', 'pin_drift', 'pinned_to_vulnerable'] as $expected) {
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
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function liftCases(): array {
		return [
			'disabled' => ['1', 'hermiq was forced to run past its declared maximum. After the upgrade to Nextcloud 34 the override is lifted, and hermiq is disabled because its version does not declare support for Nextcloud 34. Update it, or choose "Enable anyway" again.'],
			'kept enabled' => ['0', 'hermiq was forced to run past its declared maximum. After the upgrade to Nextcloud 34 the override is lifted; hermiq now runs under Nextcloud\'s own compatibility check.'],
		];
	}

	#[DataProvider('liftCases')]
	public function testForceLiftedNamesTheAppTheMajorAndWhetherItWasDisabled(string $disabled, string $message): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('force_lifted');
		$notification->method('getSubjectParameters')->willReturn(['app' => 'hermiq', 'major' => '34', 'disabled' => $disabled]);
		$notification->expects($this->once())->method('setParsedSubject')->with('Compatibility override lifted for hermiq')->willReturnSelf();
		$notification->expects($this->once())->method('setParsedMessage')->with($message)->willReturnSelf();

		(new Notifier($this->l10nFactory()))->prepare($notification, 'en');
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

	/**
	 * `advisory_assigned` names who assigned which advisory for which app, and
	 * says "the Nextcloud server" for the `:server` row (advisories-triage D3).
	 */
	public function testAdvisoryAssignedNamesTheAppTheAdvisoryAndWhoAssignedIt(): void {
		$notifier = new Notifier($this->l10nFactory());
		$messages = [];
		foreach (['openregister', ':server'] as $app) {
			$notification = $this->createMock(INotification::class);
			$notification->method('getApp')->willReturn(Application::APP_ID);
			$notification->method('getSubject')->willReturn('advisory_assigned');
			$notification->method('getSubjectParameters')->willReturn(['app' => $app, 'advisory' => 'GHSA-1', 'by' => 'alice']);
			$notification->expects($this->once())->method('setParsedSubject')->with('A security advisory was assigned to you')->willReturnSelf();
			$notification->expects($this->once())->method('setParsedMessage')->willReturnCallback(function (string $message) use ($notification, &$messages): INotification {
				$messages[] = $message;

				return $notification;
			});
			$notifier->prepare($notification, 'en');
		}

		$this->assertSame([
			'alice assigned advisory GHSA-1 for openregister to you. Review it on the Versioniq Advisories tab.',
			'alice assigned advisory GHSA-1 for the Nextcloud server to you. Review it on the Versioniq Advisories tab.',
		], $messages);
	}

	/**
	 * The server end-of-life warning names the major, the date and the days
	 * left (inventory-lifecycle-signals, design D3).
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
	 */
	public function testServerEolNamesTheMajorTheDateAndTheDaysLeft(): void {
		$notifier = new Notifier($this->l10nFactory());
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn(Application::APP_ID);
		$notification->method('getSubject')->willReturn('server_eol');
		$notification->method('getSubjectParameters')->willReturn(['major' => '31', 'date' => '2026-06-30', 'daysRemaining' => 90]);
		$notification->expects($this->once())->method('setParsedSubject')->with('Nextcloud 31 reaches end of life soon')->willReturnSelf();
		$notification->expects($this->once())->method('setParsedMessage')
			->with('Nextcloud 31 stops receiving updates on 2026-06-30, in 90 days. Plan the upgrade to a maintained version.')
			->willReturnSelf();

		$this->assertSame($notification, $notifier->prepare($notification, 'en'));
	}
}
