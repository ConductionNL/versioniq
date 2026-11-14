<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inventory-upgrade-readiness task 2.2: the queued job runs one check with
 * the targets it carries and stores the report with its time.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\BackgroundJob\UpgradeReadinessJob;
use OCA\Versioniq\Service\Readiness\ReadinessResultStore;
use OCA\Versioniq\Service\Readiness\UpgradeReadinessService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

final class UpgradeReadinessJobTest extends TestCase {
	private function job(UpgradeReadinessService $service, ReadinessResultStore $store, ?LoggerInterface $logger = null): UpgradeReadinessJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);

		return new UpgradeReadinessJob($time, $service, $store, $logger ?? $this->createMock(LoggerInterface::class));
	}

	private static function runJob(UpgradeReadinessJob $job, mixed $argument): void {
		(new ReflectionMethod(UpgradeReadinessJob::class, 'run'))->invoke($job, $argument);
	}

	public function testItChecksTheTargetsAndStoresTheReportWithItsTime(): void {
		$report = ['server' => '33', 'php' => '8.2', 'apps' => [['id' => 'oldapp', 'verdict' => 'blocked']]];
		$service = $this->createMock(UpgradeReadinessService::class);
		$service->expects(self::once())->method('check')->with('33', '8.2')->willReturn($report);
		$store = $this->createMock(ReadinessResultStore::class);
		$store->expects(self::once())->method('save')->with($report, 1_790_000_000);

		self::runJob($this->job($service, $store), ['server' => ' 33 ', 'php' => '8.2']);
	}

	public function testAJobWithoutBothTargetsChecksNothing(): void {
		$service = $this->createMock(UpgradeReadinessService::class);
		$service->expects(self::never())->method('check');
		$store = $this->createMock(ReadinessResultStore::class);
		$store->expects(self::never())->method('save');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(3))->method('warning');
		$job = $this->job($service, $store, $logger);

		self::runJob($job, ['server' => '33']);
		self::runJob($job, ['server' => '', 'php' => '8.2']);
		self::runJob($job, 'not an array');
	}

	public function testAFailedCheckIsLoggedAndKeepsTheLastReport(): void {
		$service = $this->createMock(UpgradeReadinessService::class);
		$service->method('check')->willThrowException(new RuntimeException('store down'));
		$store = $this->createMock(ReadinessResultStore::class);
		$store->expects(self::never())->method('save');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(self::stringContains('store down'));

		self::runJob($this->job($service, $store, $logger), ['server' => '33', 'php' => '8.2']);
	}

	public function testTargets(): void {
		self::assertSame(['server' => '33', 'php' => '8.4'], UpgradeReadinessJob::targets(['server' => '33', 'php' => ' 8.4', 'other' => 1]));
		self::assertNull(UpgradeReadinessJob::targets(['server' => 33, 'php' => '8.4']));
		self::assertNull(UpgradeReadinessJob::targets(null));
	}
}
