<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryTriage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Advisory\AdvisoryTriage
 * @uses   \OCA\Versioniq\Service\Advisory\AdvisoryTriageStore
 */
final class AdvisoryTriageTest extends TestCase {
	private const NOW = '2026-09-27T12:00:00+00:00';

	private const ONE = ['id' => 'GHSA-1111', 'severity' => 'high', 'summary' => 'Shares leak'];

	private const TWO = ['id' => 'GHSA-2222', 'severity' => 'low', 'summary' => 'Header missing'];

	/**
	 * A vulnerable row with both advisories.
	 *
	 * @return array<string, mixed>
	 */
	private static function vulnerable(): array {
		return ['appId' => 'openregister', 'installedVersion' => '2.3.0', 'state' => 'pinned-to-vulnerable', 'advisories' => [self::ONE, self::TWO], 'recommendedVersion' => '2.3.4', 'error' => null];
	}

	/**
	 * A dismissal made on 2.3.0.
	 *
	 * @return array<string, mixed>
	 */
	private static function dismissal(?string $until = null, string $version = '2.3.0'): array {
		return ['reason' => 'not_used', 'comment' => 'Public shares are off.', 'by' => 'alice', 'at' => '2026-09-20T10:00:00+00:00', 'until' => $until, 'installedVersion' => $version];
	}

	public function testWithoutTriageTheRowStaysAsItIsWithAnEmptyDismissedList(): void {
		$row = AdvisoryTriage::apply(self::vulnerable(), [], self::NOW);

		$this->assertSame(array_replace(self::vulnerable(), ['dismissedAdvisories' => []]), $row);
	}

	public function testOneOfTwoDismissedKeepsTheRowVulnerableAndItsRecommendation(): void {
		$row = AdvisoryTriage::apply(self::vulnerable(), ['openregister|GHSA-1111' => ['dismissal' => self::dismissal()]], self::NOW);

		$this->assertSame([self::TWO], $row['advisories']);
		$this->assertSame([array_replace(self::ONE, ['dismissal' => self::dismissal()])], $row['dismissedAdvisories']);
		$this->assertSame('pinned-to-vulnerable', $row['state']);
		$this->assertSame('2.3.4', $row['recommendedVersion']);
	}

	public function testAllDismissedDropsAVulnerableRowToAvailableWithoutARecommendation(): void {
		$row = AdvisoryTriage::apply(self::vulnerable(), [
			'openregister|GHSA-1111' => ['dismissal' => self::dismissal()],
			'openregister|GHSA-2222' => ['dismissal' => self::dismissal('2026-10-01')],
		], self::NOW);

		$this->assertSame([], $row['advisories']);
		$this->assertCount(2, $row['dismissedAdvisories']);
		$this->assertSame('advisory-available', $row['state']);
		$this->assertNull($row['recommendedVersion']);
	}

	public function testAllDismissedDropsAnAvailableRowToNone(): void {
		$available = array_replace(self::vulnerable(), ['state' => 'advisory-available', 'advisories' => [self::ONE], 'recommendedVersion' => null]);

		$row = AdvisoryTriage::apply($available, ['openregister|GHSA-1111' => ['dismissal' => self::dismissal()]], self::NOW);

		$this->assertSame('none', $row['state']);
	}

	public function testADismissalLapsesWhenItsEndDateHasPassed(): void {
		$row = AdvisoryTriage::apply(self::vulnerable(), ['openregister|GHSA-1111' => ['dismissal' => self::dismissal('2026-09-27T11:59:59+00:00')]], self::NOW);

		$this->assertSame([self::ONE, self::TWO], $row['advisories']);
		$this->assertSame([], $row['dismissedAdvisories']);
		$this->assertSame('pinned-to-vulnerable', $row['state']);
	}

	public function testADismissalLapsesWhenAnotherVersionIsInstalled(): void {
		$row = AdvisoryTriage::apply(self::vulnerable(), [
			'openregister|GHSA-1111' => ['dismissal' => self::dismissal(null, '2.2.9')],
			'openregister|GHSA-2222' => ['dismissal' => self::dismissal(null, '2.2.9')],
		], self::NOW);

		$this->assertSame([self::ONE, self::TWO], $row['advisories']);
		$this->assertSame('pinned-to-vulnerable', $row['state']);
		$this->assertSame('2.3.4', $row['recommendedVersion']);
	}

	public function testAnUnreadableEndDateCountsAsPassed(): void {
		$row = AdvisoryTriage::apply(self::vulnerable(), ['openregister|GHSA-1111' => ['dismissal' => self::dismissal('someday')]], self::NOW);

		$this->assertSame([], $row['dismissedAdvisories']);
	}

	public function testATriageEntryForAnotherAppDoesNotApply(): void {
		$row = AdvisoryTriage::apply(self::vulnerable(), [':server|GHSA-1111' => ['dismissal' => self::dismissal()]], self::NOW);

		$this->assertSame([self::ONE, self::TWO], $row['advisories']);
	}

	public function testAnOwnerTravelsWithItsAdvisoryWhetherDismissedOrNot(): void {
		$owner = ['type' => 'group', 'id' => 'admin'];

		$row = AdvisoryTriage::apply(self::vulnerable(), [
			'openregister|GHSA-1111' => ['assignee' => $owner],
			'openregister|GHSA-2222' => ['assignee' => ['type' => 'user', 'id' => 'bob'], 'dismissal' => self::dismissal()],
		], self::NOW);

		$this->assertSame($owner, $row['advisories'][0]['assignee']);
		$this->assertSame(['type' => 'user', 'id' => 'bob'], $row['dismissedAdvisories'][0]['assignee']);
	}
}
