<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Listener;

use OCA\Versioniq\Listener\AppUpdatedListener;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Audit\ExternalChangeRecorder;
use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCA\Versioniq\Service\Audit\TriggerDescriber;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCP\App\Events\AppUpdateEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

final class AppUpdatedListenerTest extends TestCase {
	public function testIgnoresUnrelatedEvents(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->never())->method('getAppVersion');
		$driftHandler = $this->createMock(PinDriftHandler::class);
		$driftHandler->expects($this->never())->method('handle');

		$listener = new AppUpdatedListener($appManager, $driftHandler);
		/** @var Event $event */
		$event = $this->createMock(UserDeletedEvent::class);
		$listener->handle($event);
	}

	public function testDelegatesToDriftHandlerWithTheFreshVersion(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('openregister', false)->willReturn('2.5.0');

		$driftHandler = $this->createMock(PinDriftHandler::class);
		$driftHandler->expects($this->once())->method('handle')->with('openregister', '2.5.0');

		$listener = new AppUpdatedListener($appManager, $driftHandler);
		$listener->handle(new AppUpdateEvent('openregister'));
	}

	public function testSkipsWhenInstalledVersionIsUnknown(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('');

		$driftHandler = $this->createMock(PinDriftHandler::class);
		$driftHandler->expects($this->never())->method('handle');

		$listener = new AppUpdatedListener($appManager, $driftHandler);
		$listener->handle(new AppUpdateEvent('openregister'));
	}

	/**
	 * A real recorder over a seen state holding $seen, writing into $rows.
	 *
	 * @param array<string, array{version: string, enabled: bool}> $seen
	 * @param list<array{0: AuditSubject, 1: AuditedVersions, 2: ?string}> $rows
	 */
	private function recorder(array $seen, array &$rows): ExternalChangeRecorder {
		$stored = json_encode(['serverVersion' => '33.0.2', 'apps' => (object)$seen, 'disabledByUpdate' => (object)[]]);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static function () use (&$stored): string {
			return $stored;
		});
		$config->method('setValueString')->willReturnCallback(static function (string $app, string $key, string $value) use (&$stored): bool {
			$stored = $value;

			return true;
		});
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(static function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null) use (&$rows): void {
			$rows[] = [$subject, $versions, $message];
		});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$bindings = $this->createMock(SourceBindingStore::class);
		$bindings->method('get')->willReturn(null);
		$triggers = $this->createMock(TriggerDescriber::class);
		$triggers->method('describe')->willReturn('POST /settings/apps/update/calendar');

		return new ExternalChangeRecorder(new SeenStateStore($config), $audit, $session, $bindings, $triggers, $this->createMock(IAppManager::class));
	}

	/**
	 * @spec openspec/changes/audit-external-changes
	 */
	public function testAnUnpinnedAppUpdateIsRecordedWithItsActorAndTrigger(): void {
		$rows = [];
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('5.2.0');

		(new AppUpdatedListener($appManager, $this->createMock(PinDriftHandler::class), $this->recorder(['calendar' => ['version' => '5.1.0', 'enabled' => true]], $rows)))
			->handle(new AppUpdateEvent('calendar'));

		$this->assertCount(1, $rows);
		[$subject, $versions, $message] = $rows[0];
		$this->assertSame(['bob', 'calendar', 'external_update'], [$subject->actorUid, $subject->appId, $subject->operation]);
		$this->assertSame(['5.1.0', '5.2.0', null], [$versions->fromVersion, $versions->toVersion, $versions->sourceId]);
		$this->assertSame('POST /settings/apps/update/calendar', $message);
	}

	/**
	 * @spec openspec/changes/audit-external-changes
	 */
	public function testAPinnedAppIsRecordedNextToItsDrift(): void {
		$rows = [];
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('2.5.0');
		$drift = $this->createMock(PinDriftHandler::class);
		$drift->expects($this->once())->method('handle')->with('openregister', '2.5.0');

		(new AppUpdatedListener($appManager, $drift, $this->recorder(['openregister' => ['version' => '2.3.0', 'enabled' => true]], $rows)))
			->handle(new AppUpdateEvent('openregister'));

		$this->assertCount(1, $rows);
		$this->assertSame('external_update', $rows[0][0]->operation);
	}

	/**
	 * @spec openspec/changes/audit-external-changes
	 */
	public function testAnUnchangedVersionIsNotRecordedTwice(): void {
		$rows = [];
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('5.2.0');
		$listener = new AppUpdatedListener($appManager, $this->createMock(PinDriftHandler::class), $this->recorder(['calendar' => ['version' => '5.1.0', 'enabled' => true]], $rows));

		$listener->handle(new AppUpdateEvent('calendar'));
		$listener->handle(new AppUpdateEvent('calendar'));

		$this->assertCount(1, $rows);
	}

	/**
	 * @spec openspec/changes/audit-external-changes
	 */
	public function testAFailingRecorderNeverFailsTheUpdate(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('5.2.0');
		$recorder = $this->createMock(ExternalChangeRecorder::class);
		$recorder->expects($this->once())->method('versionChanged')->willThrowException(new \RuntimeException('database gone'));

		(new AppUpdatedListener($appManager, $this->createMock(PinDriftHandler::class), $recorder))->handle(new AppUpdateEvent('calendar'));
	}
}
