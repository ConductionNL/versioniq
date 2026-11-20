<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Report;

require_once __DIR__ . '/../../Support/AuditRows.php';

use DateTimeImmutable;
use DateTimeZone;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Audit\AuditPeriod;
use OCA\Versioniq\Service\Report\SecurityReportService;
use OCA\Versioniq\Tests\Unit\Support\AuditRows;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Report\SecurityReportService
 * @uses   \OCA\Versioniq\Service\Audit\AuditPeriod
 * @uses   \OCA\Versioniq\Service\Audit\SecurityUpdateMatcher
 * @uses   \OCA\Versioniq\Db\AuditEntry
 */
final class SecurityReportServiceTest extends TestCase {
	private function service(?AuditEntryMapper $mapper = null, int $retention = 365): SecurityReportService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn($retention);
		return new SecurityReportService($mapper ?? $this->createMock(AuditEntryMapper::class), $config);
	}

	private static function advisory(string $id, string $severity): array {
		return ['advisories' => [['id' => $id, 'severity' => $severity]]];
	}

	/**
	 * November 2026, with a high advisory fixed by update in 3 days, a high
	 * one dismissed after 20 days, a high one still open since 1 October, a
	 * critical one from the first run fixed in 1 day, and an install.
	 */
	private function history(): array {
		return [
			AuditRows::entry(1, '2026-10-01 00:00:00', 'advisory_open', ['message' => 'tracking started'] + self::advisory('C-1', 'critical')),
			AuditRows::entry(2, '2026-10-01 00:00:00', 'advisory_open', self::advisory('H-OPEN', 'high')),
			AuditRows::entry(3, '2026-10-20 00:00:00', 'advisory_open', ['appId' => 'other'] + self::advisory('H-DISMISS', 'high')),
			AuditRows::entry(4, '2026-11-01 00:00:00', 'advisory_open', self::advisory('H-FIX', 'HIGH')),
			AuditRows::entry(5, '2026-11-02 00:00:00', 'advisory_resolved', ['message' => 'update', 'to' => '1.0.1'] + self::advisory('C-1', 'critical')),
			AuditRows::entry(6, '2026-11-04 00:00:00', 'install', ['to' => '1.0.2']),
			AuditRows::entry(7, '2026-11-04 01:00:00', 'advisory_resolved', ['message' => 'update', 'to' => '1.0.2'] + self::advisory('H-FIX', 'high')),
			AuditRows::entry(8, '2026-11-09 00:00:00', 'advisory_resolved', ['appId' => 'other', 'message' => 'dismissed'] + self::advisory('H-DISMISS', 'high')),
			AuditRows::entry(9, '2026-11-10 00:00:00', 'install', ['appId' => 'other', 'to' => '9.0.0', 'status' => 'failure']),
		];
	}

	private function compute(array $deadlines, string $retentionCut = '2026-01-01 00:00:00'): array {
		return $this->service()->compute(
			entries: $this->history(),
			period: AuditPeriod::fromDays('2026-11-01', '2026-11-30', ''),
			context: ['deadlines' => $deadlines + array_fill_keys(SecurityReportService::SEVERITIES, null), 'retentionDays' => 365, 'retentionCut' => $retentionCut, 'trackingSince' => '2026-10-01 00:00:00'],
		);
	}

	public function testWithinAndPastTheDeadlineAndADismissalAsAResolution(): void {
		$high = $this->compute(['high' => 7])['severities']['high'];

		$this->assertSame(1, $high['opened']);
		$this->assertSame(1, $high['resolvedByUpdate']);
		$this->assertSame(1, $high['resolvedByDismissal']);
		$this->assertSame(1, $high['resolvedWithinDeadline']);
		$this->assertSame(0.5, $high['withinDeadlineShare']);
		$this->assertSame(1, $high['openPastDeadline']);
		$this->assertSame(0, $high['openExceptions']);
		$this->assertSame(11.5, $high['medianDays']);
	}

	public function testTrackingStartedCountsInTotalsButNotInTheTimeFigures(): void {
		$critical = $this->compute(['critical' => 1])['severities']['critical'];

		$this->assertSame(0, $critical['opened']);
		$this->assertSame(1, $critical['resolvedByUpdate']);
		$this->assertSame(0, $critical['resolvedWithinDeadline']);
		$this->assertNull($critical['withinDeadlineShare']);
		$this->assertNull($critical['medianDays']);
	}

	public function testWithoutADeadlineTheDeadlineFiguresAreNull(): void {
		$report = $this->compute([]);
		$high = $report['severities']['high'];

		$this->assertNull($high['resolvedWithinDeadline']);
		$this->assertNull($high['withinDeadlineShare']);
		$this->assertNull($high['openPastDeadline']);
		$this->assertSame(11.5, $high['medianDays']);
		$this->assertSame(array_fill_keys(SecurityReportService::SEVERITIES, null), $report['deadlines']);
	}

	public function testSecurityUpdatesAndInstallsOfThePeriod(): void {
		$report = $this->compute([]);

		$this->assertSame([6], array_column($report['securityUpdates'], 'id'));
		$this->assertSame([['id' => 'H-FIX', 'severity' => 'high']], $report['securityUpdates'][0]['fixedAdvisories']);
		$this->assertSame(['failure' => 1, 'success' => 1], $report['installs']);
		$this->assertSame('2026-10-01 00:00:00', $report['trackingSince']);
	}

	public function testTheTrendCoversTwelveMonthsCutAtRetention(): void {
		$full = $this->compute(['high' => 7], '2025-01-01 00:00:00');
		$this->assertSame('2025-12', $full['trend'][0]['month']);
		$this->assertCount(12, $full['trend']);
		$november = end($full['trend']);
		$this->assertSame(['month' => '2026-11', 'opened' => 1, 'resolved' => 3, 'withinDeadlineShare' => 0.5, 'medianDays' => 11.5], $november);
		$this->assertSame(3, $full['trend'][10]['opened']);

		$cut = $this->compute([], '2026-09-15 00:00:00')['trend'];
		$this->assertSame(['2026-09', '2026-10', '2026-11'], array_column($cut, 'month'));
	}

	public function testReportReadsFromTheRetentionCutAndTheFirstOpening(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$first = AuditRows::entry(1, '2026-03-01 00:00:00', 'advisory_open', self::advisory('X', 'low'));
		$mapper->expects($this->exactly(2))->method('findBetween')->willReturnCallback(
			function (string $from, ?string $to, ?string $app, ?string $operation, int $limit) use ($first): array {
				if ($operation === 'advisory_open') {
					$this->assertSame(['1970-01-01 00:00:00', null, null, 1], [$from, $to, $app, $limit]);
					return [$first];
				}

				$this->assertSame(['2026-10-15 12:00:00', null, null, null], [$from, $to, $app, $operation]);
				return $this->history();
			}
		);

		$report = $this->service($mapper, 30)->report(
			AuditPeriod::fromDays('2026-11-01', '2026-11-30', ''),
			new DateTimeImmutable('2026-11-14 12:00:00', new DateTimeZone('UTC')),
		);

		$this->assertSame('2026-03-01 00:00:00', $report['trackingSince']);
		$this->assertSame(30, $report['retentionDays']);
		// October ends inside the 30-day window, so it stays; September does not.
		$this->assertSame(['2026-10', '2026-11'], array_column($report['trend'], 'month'));
	}
}
