<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSchedule;
use OCA\Versioniq\Service\AutoUpdate\UpdateType;
use PHPUnit\Framework\TestCase;

final class AutoUpdateScheduleTest extends TestCase {
	private function day(string $date): \DateTimeImmutable {
		return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
	}

	public function testTheDefaultRunsEveryKindEveryDay(): void {
		$schedule = AutoUpdateSchedule::everyDay();

		// 2026-11-14 is a Saturday.
		$this->assertSame(UpdateType::ALL, $schedule->dueKinds($this->day('2026-11-14')));
		$this->assertSame([1, 2, 3, 4, 5, 6, 7], $schedule->toArray()[UpdateType::MAJOR]['days']);
	}

	public function testMajorUpdatesOnlyOnTheirWeekday(): void {
		$schedule = AutoUpdateSchedule::fromArray(['major' => ['days' => [6], 'monthly' => false]]);

		$this->assertSame(UpdateType::ALL, $schedule->dueKinds($this->day('2026-11-14')));
		$this->assertSame([UpdateType::PATCH, UpdateType::MINOR], $schedule->dueKinds($this->day('2026-11-13')));
	}

	public function testAnEmptyDayListNeverRunsThatKind(): void {
		$schedule = AutoUpdateSchedule::fromArray(['minor' => ['days' => []]]);

		$this->assertNotContains(UpdateType::MINOR, $schedule->dueKinds($this->day('2026-11-14')));
		$this->assertNull($schedule->nextRun(UpdateType::MINOR, '01:00-05:00', $this->day('2026-11-14 12:00')));
	}

	public function testMonthlyMeansOnlyTheFirstSuchWeekdayOfTheMonth(): void {
		$schedule = AutoUpdateSchedule::fromArray(['major' => ['days' => [6, 7], 'monthly' => true]]);

		// Saturday 7 November is the first Saturday; Sunday 8 November is the second Sunday.
		$this->assertContains(UpdateType::MAJOR, $schedule->dueKinds($this->day('2026-11-07')));
		$this->assertNotContains(UpdateType::MAJOR, $schedule->dueKinds($this->day('2026-11-08')));
	}

	public function testDaysAreDeduplicatedAndSorted(): void {
		$schedule = AutoUpdateSchedule::fromArray(['patch' => ['days' => [5, 1, 5]]]);

		$this->assertSame([1, 5], $schedule->toArray()[UpdateType::PATCH]['days']);
	}

	public function testNextRunIsTheNextDueOpening(): void {
		$schedule = AutoUpdateSchedule::fromArray(['major' => ['days' => [6]]]);

		// Monday noon: next Saturday 01:00.
		$next = $schedule->nextRun(UpdateType::MAJOR, '01:00-05:00', $this->day('2026-11-09 12:00'));

		$this->assertSame('2026-11-14T01:00:00+00:00', $next?->format(\DateTimeInterface::ATOM));
	}

	public function testNextRunIsNowWhileADueWindowIsOpen(): void {
		$schedule = AutoUpdateSchedule::fromArray(['major' => ['days' => [5]]]);
		$now = $this->day('2026-11-14 00:30');

		// The 23:00-03:00 window opened on Friday the 13th, which is due.
		$this->assertEquals($now, $schedule->nextRun(UpdateType::MAJOR, '23:00-03:00', $now));
	}

	public function testNextRunIsNullForAMalformedWindow(): void {
		$this->assertNull(AutoUpdateSchedule::everyDay()->nextRun(UpdateType::PATCH, 'later', $this->day('2026-11-14')));
	}

	public function testInvalidReasonNamesTheField(): void {
		$this->assertNull(AutoUpdateSchedule::invalidReason(['patch' => ['days' => [1, 7], 'monthly' => true]]));
		$this->assertNull(AutoUpdateSchedule::invalidReason([]));
		$this->assertStringContainsString('schedule.major.days', (string)AutoUpdateSchedule::invalidReason(['major' => ['days' => [8]]]));
		$this->assertStringContainsString('schedule.weekly', (string)AutoUpdateSchedule::invalidReason(['weekly' => ['days' => [1]]]));
		$this->assertStringContainsString('schedule.minor.monthly', (string)AutoUpdateSchedule::invalidReason(['minor' => ['days' => [1], 'monthly' => 'yes']]));
		$this->assertStringContainsString('twice', (string)AutoUpdateSchedule::invalidReason(['minor' => ['days' => [1, 1]]]));
		$this->assertNotNull(AutoUpdateSchedule::invalidReason('everyday'));
	}
}
