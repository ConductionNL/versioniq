<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\AutoUpdate\SecurityCandidate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The security candidate for an app on the `security` level
 * (autoupdate-security-first D1 and D2).
 *
 * @covers \OCA\Versioniq\Service\AutoUpdate\SecurityCandidate
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
final class SecurityCandidateTest extends TestCase {
	/**
	 * An affected row recommending `$recommended`.
	 *
	 * @return array<string, mixed>
	 */
	private static function affected(?string $recommended): array {
		return [
			'appId' => 'openregister',
			'installedVersion' => '2.3.4',
			'state' => AdvisoryService::STATE_VULNERABLE,
			'advisories' => [['id' => 'GHSA-1', 'severity' => 'high', 'summary' => 'XSS']],
			'recommendedVersion' => $recommended,
			'error' => null,
		];
	}

	public function testAnAffectedAppGetsTheRecommendedFix(): void {
		$this->assertSame(
			['version' => '2.3.5', 'reason' => null],
			SecurityCandidate::decide('2.3.4', self::affected('2.3.5'), ['2.3.5', '2.4.0', '3.0.0'], true)
		);
	}

	public function testTheRecommendedFixWinsOverAHigherVersionInTheSameMajor(): void {
		$this->assertSame('2.3.5', SecurityCandidate::decide('2.3.4', self::affected('2.3.5'), ['2.9.9', '2.3.5'], true)['version']);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null, 1: list<string>, 2: bool, 3: string}>
	 */
	public static function refusals(): array {
		return [
			'no row' => [null, ['2.3.5'], true, SecurityCandidate::REASON_NOT_AFFECTED],
			'advisories that do not affect it' => [['state' => AdvisoryService::STATE_AVAILABLE, 'recommendedVersion' => null], ['2.3.5'], true, SecurityCandidate::REASON_NOT_AFFECTED],
			'no advisories' => [['state' => AdvisoryService::STATE_NONE], ['2.3.5'], true, SecurityCandidate::REASON_NOT_AFFECTED],
			'affected without a fix' => [self::affected(null), ['2.3.5'], true, SecurityCandidate::REASON_NO_FIX],
			'a fix that is not newer' => [self::affected('2.3.4'), ['2.3.4'], true, SecurityCandidate::REASON_NO_FIX],
			'the fix needs a new major' => [self::affected('3.0.1'), ['3.0.1'], true, SecurityCandidate::REASON_NEEDS_MAJOR],
			'the source does not list the fix' => [self::affected('2.3.5'), ['2.4.0'], true, SecurityCandidate::REASON_NOT_IN_SOURCE],
			'a stale snapshot' => [self::affected('2.3.5'), ['2.3.5'], false, SecurityCandidate::REASON_STALE],
		];
	}

	/**
	 * @param array<string, mixed>|null $row
	 * @param list<string>              $available
	 */
	#[DataProvider('refusals')]
	public function testEachReasonInstallsNothing(?array $row, array $available, bool $fresh, string $reason): void {
		$this->assertSame(['version' => null, 'reason' => $reason], SecurityCandidate::decide('2.3.4', $row, $available, $fresh));
	}

	public function testASnapshotIsFreshForTwoIntervals(): void {
		$sixHours = 6 * 3600;
		$now = 1_800_000_000;

		$this->assertTrue(SecurityCandidate::isFresh($now - $sixHours, $now, $sixHours));
		$this->assertTrue(SecurityCandidate::isFresh($now - 2 * $sixHours, $now, $sixHours));
		$this->assertFalse(SecurityCandidate::isFresh($now - 2 * $sixHours - 1, $now, $sixHours));
		$this->assertFalse(SecurityCandidate::isFresh(null, $now, $sixHours));
		$this->assertFalse(SecurityCandidate::isFresh(0, $now, $sixHours));
	}
}
