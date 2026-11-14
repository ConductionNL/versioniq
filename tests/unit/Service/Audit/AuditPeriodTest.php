<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Audit;

use InvalidArgumentException;
use OCA\Versioniq\Service\Audit\AuditPeriod;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Audit\AuditPeriod
 */
final class AuditPeriodTest extends TestCase {
	public function testWholeDaysInTheAdminsTimeZoneBecomeUtcBounds(): void {
		$period = AuditPeriod::fromDays('2026-11-01', '2026-11-30', 'Europe/Amsterdam');

		$this->assertSame('2026-10-31 23:00:00', $period->fromUtc);
		$this->assertSame('2026-11-30 22:59:59', $period->toUtc);
		$this->assertTrue($period->contains('2026-10-31 23:00:00'));
		$this->assertTrue($period->contains('2026-11-30 22:59:59'));
		$this->assertFalse($period->contains('2026-10-31 22:59:59'));
		$this->assertFalse($period->contains('2026-11-30 23:00:00'));
	}

	public function testMissingBoundsAreOpenAndTheZoneDefaultsToUtc(): void {
		$period = AuditPeriod::fromDays('', '2026-11-30', '');

		$this->assertSame('1970-01-01 00:00:00', $period->fromUtc);
		$this->assertSame('2026-11-30 23:59:59', $period->toUtc);
		$this->assertNull(AuditPeriod::fromDays('2026-11-01', '', '')->toUtc);
	}

	public function testABadDayIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Dates are YYYY-MM-DD.');
		AuditPeriod::fromDays('2026-02-30', '', '');
	}

	public function testABadTimeZoneIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown time zone.');
		AuditPeriod::fromDays('2026-11-01', '', 'Mars/Olympus');
	}

	public function testAPeriodThatEndsBeforeItStartsIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The period starts after it ends.');
		AuditPeriod::fromDays('2026-11-02', '2026-11-01', '');
	}
}
