<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use PHPUnit\Framework\TestCase;

final class AutoUpdateWindowTest extends TestCase {
	public function testIsValidAcceptsAWellFormedWindow(): void {
		$this->assertTrue(AutoUpdateWindow::isValid('01:00-05:00'));
		$this->assertTrue(AutoUpdateWindow::isValid('23:00-03:00'));
	}

	public function testIsValidRejectsMalformedWindows(): void {
		$this->assertFalse(AutoUpdateWindow::isValid('1:00-05:00'));
		$this->assertFalse(AutoUpdateWindow::isValid('25:00-05:00'));
		$this->assertFalse(AutoUpdateWindow::isValid('01:00'));
		$this->assertFalse(AutoUpdateWindow::isValid(''));
		$this->assertFalse(AutoUpdateWindow::isValid('01:60-05:00'));
	}

	public function testIsWithinTrueInsideANonCrossingWindow(): void {
		$now = new \DateTimeImmutable('2026-07-23 02:30:00');

		$this->assertTrue(AutoUpdateWindow::isWithin('01:00-05:00', $now));
	}

	public function testIsWithinFalseOutsideANonCrossingWindow(): void {
		$now = new \DateTimeImmutable('2026-07-23 13:00:00');

		$this->assertFalse(AutoUpdateWindow::isWithin('01:00-05:00', $now));
	}

	public function testIsWithinTrueAtMidnightCrossingWindowJustAfterMidnight(): void {
		// GIVEN window 23:00-03:00, WHEN the job fires at 00:30 THEN it MUST be
		// considered inside the window; see "Midnight-crossing window".
		$now = new \DateTimeImmutable('2026-07-23 00:30:00');

		$this->assertTrue(AutoUpdateWindow::isWithin('23:00-03:00', $now));
	}

	public function testIsWithinTrueAtMidnightCrossingWindowBeforeMidnight(): void {
		$now = new \DateTimeImmutable('2026-07-23 23:30:00');

		$this->assertTrue(AutoUpdateWindow::isWithin('23:00-03:00', $now));
	}

	public function testIsWithinFalseOutsideAMidnightCrossingWindow(): void {
		$now = new \DateTimeImmutable('2026-07-23 12:00:00');

		$this->assertFalse(AutoUpdateWindow::isWithin('23:00-03:00', $now));
	}

	public function testIsWithinFalseForAMalformedWindow(): void {
		$now = new \DateTimeImmutable('2026-07-23 02:00:00');

		$this->assertFalse(AutoUpdateWindow::isWithin('garbage', $now));
	}

	public function testIsWithinFalseForAZeroWidthWindow(): void {
		$now = new \DateTimeImmutable('2026-07-23 02:00:00');

		$this->assertFalse(AutoUpdateWindow::isWithin('02:00-02:00', $now));
	}

	public function testIsWithinBoundaryStartIsInsideEndIsExclusive(): void {
		$this->assertTrue(AutoUpdateWindow::isWithin('01:00-05:00', new \DateTimeImmutable('2026-07-23 01:00:00')));
		$this->assertFalse(AutoUpdateWindow::isWithin('01:00-05:00', new \DateTimeImmutable('2026-07-23 05:00:00')));
	}

	public function testOpeningKeyOfANonCrossingWindowIsToday(): void {
		$this->assertSame('01:00-05:00@2026-07-23', AutoUpdateWindow::openingKey('01:00-05:00', new \DateTimeImmutable('2026-07-23T02:00:00')));
	}

	public function testOpeningKeyAfterMidnightInACrossingWindowIsYesterday(): void {
		$window = '23:00-03:00';
		$this->assertSame('23:00-03:00@2026-07-22', AutoUpdateWindow::openingKey($window, new \DateTimeImmutable('2026-07-22T23:30:00')));
		$this->assertSame('23:00-03:00@2026-07-22', AutoUpdateWindow::openingKey($window, new \DateTimeImmutable('2026-07-23T01:00:00')));
	}
}
