<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\BackgroundJob\PinReconcileJob;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class PinReconcileJobTest extends TestCase {
	use BuildsByParameterName;

	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-23T00:00:00+00:00'));

		return $time;
	}

	private function runJob(PinReconcileJob $job): void {
		$method = new ReflectionMethod(PinReconcileJob::class, 'run');
		$method->invoke($job, null);
	}

	public function testDelegatesEveryPinToTheDriftHandlerWithTheLiveVersion(): void {
		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('all')->willReturn([
			'openregister' => new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00'),
			'calendar' => (new Pin('1.0.0', 'bob', '2026-06-11T12:00:00+00:00'))->withDrift('1.1.0', '2026-06-12T00:00:00+00:00'),
		]);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturnMap([
			['openregister', false, '2.3.0'],
			['calendar', false, '1.1.0'],
		]);

		$driftHandler = $this->createMock(PinDriftHandler::class);
		$driftHandler->expects($this->exactly(2))->method('handle')->willReturnCallback(function (string $appId, string $version): void {
			static $calls = [];
			$calls[] = [$appId, $version];
			$this->assertContains([$appId, $version], [['openregister', '2.3.0'], ['calendar', '1.1.0']]);
		});

		$job = $this->buildByName(PinReconcileJob::class, ['time' => $this->timeFactory(), 'pinStore' => $pinStore, 'appManager' => $appManager, 'driftHandler' => $driftHandler, 'logger' => $this->createMock(LoggerInterface::class)]);
		$this->runJob($job);
	}

	public function testSkipsAppsWithNoKnownInstalledVersion(): void {
		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('all')->willReturn([
			'goneapp' => new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00'),
		]);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('');

		$driftHandler = $this->createMock(PinDriftHandler::class);
		$driftHandler->expects($this->never())->method('handle');

		$job = $this->buildByName(PinReconcileJob::class, ['time' => $this->timeFactory(), 'pinStore' => $pinStore, 'appManager' => $appManager, 'driftHandler' => $driftHandler, 'logger' => $this->createMock(LoggerInterface::class)]);
		$this->runJob($job);
	}

	public function testAFailureOnOnePinDoesNotBlockTheRest(): void {
		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('all')->willReturn([
			'broken' => new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00'),
			'fine' => new Pin('1.0.0', 'bob', '2026-06-11T12:00:00+00:00'),
		]);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturnMap([
			['broken', false, '2.5.0'],
			['fine', false, '1.0.0'],
		]);

		$driftHandler = $this->createMock(PinDriftHandler::class);
		$driftHandler->method('handle')->willReturnCallback(function (string $appId): void {
			if ($appId === 'broken') {
				throw new \RuntimeException('boom');
			}
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$job = $this->buildByName(PinReconcileJob::class, ['time' => $this->timeFactory(), 'pinStore' => $pinStore, 'appManager' => $appManager, 'driftHandler' => $driftHandler, 'logger' => $logger]);
		$this->runJob($job);
		$this->addToAssertionCount(1);
	}
}
