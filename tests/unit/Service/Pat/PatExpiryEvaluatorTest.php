<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Pat;

use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class PatExpiryEvaluatorTest extends TestCase {
	private const NOW = '2026-07-23 00:00:00';

	private function evaluator(): PatExpiryEvaluator {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(
			fn (string $when = 'now', ?\DateTimeZone $tz = null): \DateTime => new \DateTime(self::NOW, $tz ?? new \DateTimeZone('UTC'))
		);

		return new PatExpiryEvaluator($time);
	}

	private function daysFromNow(int $days): string {
		return (new \DateTimeImmutable(self::NOW))->modify(sprintf('%+d days', $days))->format('Y-m-d H:i:s');
	}

	public function testTwelveDaysRemainingCrosses14dThreshold(): void {
		$evaluator = $this->evaluator();
		$expiresAt = $this->daysFromNow(12);

		$this->assertSame(12, $evaluator->daysRemaining($expiresAt));
		$this->assertSame(PatExpiryEvaluator::THRESHOLD_14D, $evaluator->highestCrossedThreshold($expiresAt));
	}

	public function testTwoDaysRemainingCrosses3dThresholdNot14d(): void {
		$evaluator = $this->evaluator();
		$expiresAt = $this->daysFromNow(2);

		$this->assertSame(PatExpiryEvaluator::THRESHOLD_3D, $evaluator->highestCrossedThreshold($expiresAt));
	}

	public function testExpiredCrossesExpiredThreshold(): void {
		$evaluator = $this->evaluator();
		$expiresAt = $this->daysFromNow(-1);

		$this->assertSame(PatExpiryEvaluator::THRESHOLD_EXPIRED, $evaluator->highestCrossedThreshold($expiresAt));
	}

	public function testUnknownExpiryHasNoThreshold(): void {
		$evaluator = $this->evaluator();

		$this->assertNull($evaluator->highestCrossedThreshold(null));
	}

	public function testComfortablyValidTokenHasNoThreshold(): void {
		$evaluator = $this->evaluator();
		$expiresAt = $this->daysFromNow(30);

		$this->assertNull($evaluator->highestCrossedThreshold($expiresAt));
	}

	/**
	 * A token first observed at 2 days remaining (e.g. late-added / late
	 * validated) MUST get only the 3d threshold, never a retroactive 14d one.
	 */
	public function testLateAddedTokenNeverGetsRetroactive14d(): void {
		$evaluator = $this->evaluator();
		$expiresAt = $this->daysFromNow(2);

		$threshold = $evaluator->highestCrossedThreshold($expiresAt);

		$this->assertSame(PatExpiryEvaluator::THRESHOLD_3D, $threshold);
		$this->assertNotSame(PatExpiryEvaluator::THRESHOLD_14D, $threshold);
	}

	public function testEvaluateStateOk(): void {
		$evaluator = $this->evaluator();

		$result = $evaluator->evaluate($this->daysFromNow(30));

		$this->assertSame('ok', $result['state']);
		$this->assertSame(30, $result['daysRemaining']);
	}

	public function testEvaluateStateExpiring(): void {
		$evaluator = $this->evaluator();

		$result = $evaluator->evaluate($this->daysFromNow(5));

		$this->assertSame('expiring', $result['state']);
		$this->assertSame(5, $result['daysRemaining']);
	}

	public function testEvaluateStateExpired(): void {
		$evaluator = $this->evaluator();

		$result = $evaluator->evaluate($this->daysFromNow(-3));

		$this->assertSame('expired', $result['state']);
	}

	public function testEvaluateStateUnknown(): void {
		$evaluator = $this->evaluator();

		$result = $evaluator->evaluate(null);

		$this->assertSame('unknown', $result['state']);
		$this->assertNull($result['daysRemaining']);
	}
}
