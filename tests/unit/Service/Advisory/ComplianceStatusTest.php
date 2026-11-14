<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\ComplianceStatus;
use PHPUnit\Framework\TestCase;

/**
 * advisories-per-app-detail D4: one patch compliance status for the instance.
 *
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-the-instance-has-one-patch-compliance-status
 */
final class ComplianceStatusTest extends TestCase {
	private const CHECKED = 1_790_000_000;
	private const INTERVAL = 6;

	private static function row(string $state, array $severities = [], ?string $error = null): array {
		return [
			'state' => $state,
			'advisories' => array_map(static fn (string $s): array => ['id' => 'GHSA-' . $s, 'severity' => $s, 'summary' => ''], $severities),
			'error' => $error,
		];
	}

	public function testNeverCheckedIsUnknown(): void {
		$status = ComplianceStatus::from([], null, self::INTERVAL, self::CHECKED);

		self::assertSame('unknown', $status['status']);
		self::assertSame('never_checked', $status['reason']);
	}

	public function testStaleOneSecondPastTwiceTheInterval(): void {
		$rows = ['mail' => self::row(AdvisoryService::STATE_NONE)];
		$limit = self::CHECKED + 2 * self::INTERVAL * 3600;

		self::assertSame('compliant', ComplianceStatus::from($rows, self::CHECKED, self::INTERVAL, $limit)['status']);
		$stale = ComplianceStatus::from($rows, self::CHECKED, self::INTERVAL, $limit + 1);
		self::assertSame('unknown', $stale['status']);
		self::assertSame('stale', $stale['reason']);
	}

	public function testUnreachedAppsMakeItUnknownWithTheirNumber(): void {
		$rows = [
			'mail' => self::row(AdvisoryService::STATE_AVAILABLE, ['high']),
			'hermiq' => self::row(AdvisoryService::STATE_NONE, [], 'HTTP 403'),
			'notes' => self::row(AdvisoryService::STATE_NONE, [], 'budget exceeded'),
		];

		$status = ComplianceStatus::from($rows, self::CHECKED, self::INTERVAL, self::CHECKED + 60);

		self::assertSame('unknown', $status['status']);
		self::assertSame('unreached', $status['reason']);
		self::assertSame(2, $status['unreached']);
	}

	public function testNotCompliantCountsEachTargetUnderItsHighestSeverity(): void {
		$rows = [
			'mail' => self::row(AdvisoryService::STATE_VULNERABLE, ['medium', 'critical']),
			'calendar' => self::row(AdvisoryService::STATE_VULNERABLE, ['medium']),
			AdvisoryService::SERVER_KEY => self::row(AdvisoryService::STATE_VULNERABLE, ['low', 'moderate']),
			'hermiq' => self::row(AdvisoryService::STATE_NONE, [], 'HTTP 403'),
			'deck' => self::row(AdvisoryService::STATE_AVAILABLE, ['critical']),
		];

		$status = ComplianceStatus::from($rows, self::CHECKED, self::INTERVAL, self::CHECKED + 60);

		self::assertSame('not_compliant', $status['status']);
		self::assertNull($status['reason']);
		self::assertSame(3, $status['affected']);
		self::assertSame(['critical' => 1, 'medium' => 2], $status['bySeverity']);
		self::assertSame(['mail', 'calendar', AdvisoryService::SERVER_KEY], $status['affectedIds']);
		self::assertSame(1, $status['unreached']);
	}

	public function testCompliantWhenFreshReachedAndNothingAffected(): void {
		$rows = ['mail' => self::row(AdvisoryService::STATE_AVAILABLE, ['high']), 'deck' => self::row(AdvisoryService::STATE_NONE)];

		$status = ComplianceStatus::from($rows, self::CHECKED, self::INTERVAL, self::CHECKED + 60);

		self::assertSame([
			'status' => 'compliant',
			'reason' => null,
			'affected' => 0,
			'bySeverity' => [],
			'affectedIds' => [],
			'unreached' => 0,
			'checkedAt' => self::CHECKED,
		], $status);
	}
}
