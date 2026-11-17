<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Listener;

use OCA\Versioniq\Listener\AppDisabledListener;
use OCA\Versioniq\Service\Reactivation\ReactivationService;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppUpdateEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\Listener\AppDisabledListener
 */
final class AppDisabledListenerTest extends TestCase {
	private function listener(ReactivationService $reactivation, ?LoggerInterface $logger = null): AppDisabledListener {
		return new AppDisabledListener($reactivation, $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testHandsTheDisabledAppToReactivation(): void {
		$reactivation = $this->createMock(ReactivationService::class);
		$reactivation->expects($this->once())->method('disabledNow')->with('calendar')->willReturn(ReactivationService::DELIBERATE);

		$this->listener($reactivation)->handle(new AppDisableEvent('calendar'));
	}

	public function testIgnoresOtherEvents(): void {
		$reactivation = $this->createMock(ReactivationService::class);
		$reactivation->expects($this->never())->method('disabledNow');

		$this->listener($reactivation)->handle(new AppUpdateEvent('calendar'));
	}

	public function testAFailureIsLoggedAndNeverEscapes(): void {
		$reactivation = $this->createMock(ReactivationService::class);
		$reactivation->method('disabledNow')->willThrowException(new \RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('AppDisabledListener: could not record a disable', ['message' => 'database gone']);

		$this->listener($reactivation, $logger)->handle(new AppDisableEvent('calendar'));
	}
}
