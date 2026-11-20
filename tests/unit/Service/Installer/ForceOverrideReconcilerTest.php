<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

require_once __DIR__ . '/ForceEnablerTest.php';

use OCA\Versioniq\BackgroundJob\ForceOverrideReconcileJob;
use OCA\Versioniq\BackgroundJob\JobRuntime;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Installer\ForceOverride;
use OCA\Versioniq\Service\Installer\ForceOverrideReconciler;
use OCA\Versioniq\Service\Installer\ForceOverrideStore;
use OCP\App\IAppManager;
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
 * The lift after a major upgrade (install-force-incompatible D4).
 */
final class ForceOverrideReconcilerTest extends TestCase {
	/** @var list<string> */
	private array $calls = [];

	/** @var list<array<string, string>> */
	private array $notified = [];

	/** @var list<string> */
	private array $audited = [];

	/**
	 * @param array<string, ForceOverride> $records
	 * @param array<string, array<string, string>> $declared Per app, its info.xml nextcloud attributes.
	 * @param list<string> $enabled
	 */
	private function reconciler(array $records, array $declared, array $enabled, string $server = '34.0.1'): ForceOverrideReconciler {
		$overrides = $this->createMock(ForceOverrideStore::class);
		$overrides->method('all')->willReturn($records);
		$overrides->method('remove')->willReturnCallback(function (string $appId): void {
			$this->calls[] = 'remove ' . $appId;
		});
		$overrides->method('forget')->willReturnCallback(function (string $appId): void {
			$this->calls[] = 'forget ' . $appId;
		});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturnCallback(static fn (string $appId): bool => in_array($appId, $enabled, true));
		$appManager->method('getAppInfo')->willReturnCallback(static fn (string $appId): array => ['dependencies' => ['nextcloud' => ['@attributes' => $declared[$appId] ?? []]]]);
		$appManager->method('disableApp')->willReturnCallback(function (string $appId): void {
			$this->calls[] = 'disable ' . $appId;
		});

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message): void {
			$this->audited[] = $subject->operation . ' ' . $subject->appId . ' ' . $versions->fromVersion . ': ' . $message;
		});

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('alice');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$admin]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setLink'] as $setter) {
				$notification->method($setter)->willReturnSelf();
			}
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use ($notification): INotification {
				$this->notified[] = ['subject' => $subject] + $parameters;

				return $notification;
			});

			return $notification;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T08:00:00+00:00'));

		return new ForceOverrideReconciler(
			$overrides,
			$appManager,
			ForceEnablerTest::serverVersion($server),
			$audit,
			new AdminNotificationServices($notifications, $groups, $time, $this->createMock(IURLGenerator::class)),
			$this->createMock(LoggerInterface::class),
		);
	}

	private static function record(int $major, bool $added = true): ForceOverride {
		return new ForceOverride('1.4.0', $major, 'alice', '2026-10-01T00:00:00+00:00', $added);
	}

	public function testAnUnsupportedAppIsLiftedAndDisabledAfterTheUpgrade(): void {
		$lifted = $this->reconciler(['hermiq' => self::record(33)], ['hermiq' => ['min-version' => '30', 'max-version' => '33']], ['hermiq'])->reconcile();

		$this->assertSame(['hermiq'], $lifted);
		$this->assertSame(['remove hermiq', 'disable hermiq', 'forget hermiq'], $this->calls);
		$this->assertSame(['force_incompatible hermiq 1.4.0: Lifted after the upgrade to Nextcloud 34; the app was disabled.'], $this->audited);
		$this->assertSame([['subject' => 'force_lifted', 'app' => 'hermiq', 'major' => '34', 'disabled' => '1']], $this->notified);
	}

	public function testAnAppThatNowDeclaresTheNewMajorStaysEnabled(): void {
		$this->reconciler(['hermiq' => self::record(33)], ['hermiq' => ['min-version' => '30', 'max-version' => '34']], ['hermiq'])->reconcile();

		$this->assertSame(['remove hermiq', 'forget hermiq'], $this->calls);
		$this->assertSame('0', $this->notified[0]['disabled']);
	}

	public function testAnEntryVersioniqDidNotAddIsNotRemoved(): void {
		$this->reconciler(['hermiq' => self::record(33, added: false)], ['hermiq' => ['max-version' => '33']], ['hermiq'])->reconcile();

		$this->assertSame(['disable hermiq', 'forget hermiq'], $this->calls);
	}

	public function testAnOverrideOnTheRunningMajorIsLeftAlone(): void {
		$lifted = $this->reconciler(['hermiq' => self::record(34)], [], ['hermiq'])->reconcile();

		$this->assertSame([], $lifted);
		$this->assertSame([], $this->calls);
		$this->assertSame([], $this->notified);
	}

	public function testAnOverrideWithoutARecordIsNeverTouched(): void {
		// `calendar` was forced through Nextcloud's own force enable: Versioniq holds no record of it.
		$this->assertSame([], $this->reconciler([], ['calendar' => ['max-version' => '33']], ['calendar'])->reconcile());
		$this->assertSame([], $this->calls);
	}

	public function testTheHourlyJobRunsTheReconciler(): void {
		$reconciler = $this->createMock(ForceOverrideReconciler::class);
		$reconciler->expects($this->once())->method('reconcile')->willReturn([]);
		$runtime = new JobRuntime($this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class));
		$job = new ForceOverrideReconcileJob($runtime, $reconciler);

		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
		$this->assertSame(3600, (new \ReflectionProperty($job, 'interval'))->getValue($job));
	}
}
