<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Listener;

use OCA\Versioniq\Listener\AppUpdatedListener;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCP\App\Events\AppUpdateEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
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
}
