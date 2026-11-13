<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Listener;

use OCA\Versioniq\Listener\AppDisabledListener;
use OCA\Versioniq\Service\Audit\ExternalChangeRecorder;
use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCA\Versioniq\Service\Reactivation\ReactivationService;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppUpdateEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\Listener\AppDisabledListener
 */
final class AppDisabledListenerTest extends TestCase {
	private function listener(?array $state, ReactivationService $reactivation, ?LoggerInterface $logger = null): AppDisabledListener {
		$seen = $this->createMock(SeenStateStore::class);
		$seen->method('read')->willReturn($state);
		$recorder = $this->createMock(ExternalChangeRecorder::class);
		$recorder->method('actor')->willReturn('bob');
		$recorder->method('trigger')->willReturn('POST /settings/apps/disable');

		return new AppDisabledListener($seen, $reactivation, $recorder, $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testHandsTheDisableWithWhatWasSeenBeforeToReactivation(): void {
		$reactivation = $this->createMock(ReactivationService::class);
		$reactivation->expects($this->once())->method('appDisabled')
			->with('calendar', ['version' => '5.2.0', 'enabled' => true], '33.0.2', 'bob', 'POST /settings/apps/disable')
			->willReturn(ReactivationService::DELIBERATE);

		$this->listener(['serverVersion' => '33.0.2', 'apps' => ['calendar' => ['version' => '5.2.0', 'enabled' => true]], 'disabledByUpdate' => []], $reactivation)
			->handle(new AppDisableEvent('calendar'));
	}

	public function testDoesNothingBeforeTheBaselineOrForOtherEvents(): void {
		$reactivation = $this->createMock(ReactivationService::class);
		$reactivation->expects($this->never())->method('appDisabled');

		$this->listener(null, $reactivation)->handle(new AppDisableEvent('calendar'));
		$this->listener(['serverVersion' => '33.0.2', 'apps' => [], 'disabledByUpdate' => []], $reactivation)->handle(new AppUpdateEvent('calendar'));
	}

	public function testAFailureIsLoggedAndNeverEscapes(): void {
		$reactivation = $this->createMock(ReactivationService::class);
		$reactivation->method('appDisabled')->willThrowException(new \RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('AppDisabledListener: could not record a disable', ['message' => 'database gone']);

		$this->listener(['serverVersion' => '33.0.2', 'apps' => [], 'disabledByUpdate' => []], $reactivation, $logger)->handle(new AppDisableEvent('calendar'));
	}
}
