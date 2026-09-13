<?php

/**
 * Tests for the retired-Cron-namespace background-job cleanup.
 *
 * @category  Test
 * @package   OCA\Versioniq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Repair;

use OCA\Versioniq\Repair\RemoveRetiredCronJobs;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Versioniq\Repair\RemoveRetiredCronJobs
 *
 * @spec exclude No canonical spec covers the OCA\Versioniq\Cron ->
 *  OCA\Versioniq\BackgroundJob move (ADR-100 Decision 3 is an architecture
 *  record, not a capability spec). Pointing this at an existing spec would
 *  report conformance to a requirement that says nothing about it.
 */
final class RemoveRetiredCronJobsTest extends TestCase {

	/**
	 * The step removes every retired class, by its full old name.
	 *
	 * This asserts the ARGUMENTS, not just the call count. The whole value of
	 * the step is which strings it passes: a version that removed the NEW
	 * class names would satisfy a count-only assertion while deleting the
	 * registrations the app actually needs.
	 *
	 * Two namespaces are retired, not one. `OCA\Versioniq\Cron` is what the
	 * Cron -> BackgroundJob move left behind; `OCA\AppVersions` is what the
	 * app_versions -> versioniq RENAME left behind, and nothing cleaned that
	 * one until now. All five were observed on a live instance with a recent
	 * last_run — scheduled, and failing silently on every tick.
	 *
	 * @return void
	 */
	public function testRemovesEveryRetiredClassByName(): void {
		$removed = [];

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(
			static function ($class) use (&$removed): void {
				$removed[] = $class;
			}
		);

		$step = new RemoveRetiredCronJobs($jobList, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame(
			[
				'OCA\Versioniq\Cron\PinReconcileJob',
				'OCA\Versioniq\Cron\PruneAuditJob',
				'OCA\AppVersions\BackgroundJob\AdvisoryRefreshJob',
				'OCA\AppVersions\BackgroundJob\AutoUpdateJob',
				'OCA\AppVersions\BackgroundJob\PatExpiryWarningJob',
				'OCA\AppVersions\Cron\PinReconcileJob',
				'OCA\AppVersions\Cron\PruneAuditJob',
			],
			$removed,
			'the step must remove the OLD fully-qualified names — removing the new ones would '
			. 'delete the registrations the app depends on'
		);

		$this->assertNotContains(
			'OCA\Versioniq\BackgroundJob\PinReconcileJob',
			$removed,
			'the LIVE class must never be removed: that is the row the app actually runs'
		);

	}//end testRemovesEveryRetiredClassByName()

	/**
	 * A failure on one class does not abort the step or the upgrade.
	 *
	 * A repair step that raises takes the whole `occ upgrade` with it. Trading
	 * a dormant orphaned job row for an instance that will not start is the
	 * worse outcome, so the step reports and continues — and this pins that,
	 * because "continues" is exactly the behaviour a later refactor would
	 * quietly drop.
	 *
	 * @return void
	 */
	public function testAFailureOnOneClassDoesNotStopTheRest(): void {
		$attempted = [];

		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(
			static function ($class) use (&$attempted): void {
				$attempted[] = $class;
				if (str_contains($class, 'PinReconcileJob') === true) {
					throw new RuntimeException('database is gone');
				}
			}
		);

		$output = $this->createMock(IOutput::class);
		$step = new RemoveRetiredCronJobs($jobList, $this->createMock(LoggerInterface::class));

		$step->run($output);

		$this->assertContains(
			'OCA\Versioniq\Cron\PruneAuditJob',
			$attempted,
			'a failure removing the first class must not skip the second'
		);

	}//end testAFailureOnOneClassDoesNotStopTheRest()

	/**
	 * The step is a repair step and names itself.
	 *
	 * @return void
	 */
	public function testItIsARepairStepWithAName(): void {
		$step = new RemoveRetiredCronJobs(
			$this->createMock(IJobList::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(IRepairStep::class, $step);
		$this->assertNotSame('', trim($step->getName()));

	}//end testItIsARepairStepWithAName()
}//end class
