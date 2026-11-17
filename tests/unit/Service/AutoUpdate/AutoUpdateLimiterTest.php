<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateLimiter;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateMember;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The caps and the waiting list of an automatic-update run
 * (autoupdate-run-batching D4, D5): what a run may install, what waits, and
 * who goes first next time.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class AutoUpdateLimiterTest extends TestCase {
	private AutoUpdateSettingsStore&MockObject $settings;
	private AttemptLedger&MockObject $ledger;

	/** @var array<string, string> The waiting list as stored. */
	private array $deferred = [];

	private \DateTimeImmutable $now;

	protected function setUp(): void {
		$this->settings = $this->createMock(AutoUpdateSettingsStore::class);
		$this->ledger = $this->createMock(AttemptLedger::class);
		$this->now = new \DateTimeImmutable('2026-11-15T02:00:00+00:00');
		$this->settings->method('getDeferred')->willReturnCallback(fn (): array => $this->deferred);
		$this->settings->method('setDeferred')->willReturnCallback(function (array $deferred): void {
			$this->deferred = $deferred;
		});
	}

	private function caps(?int $perRun, ?int $perWeek, int $usedThisWeek = 0): void {
		$this->settings->method('getMaxPerRun')->willReturn($perRun);
		$this->settings->method('getMaxPerWeek')->willReturn($perWeek);
		$this->ledger->method('countSince')->willReturn($usedThisWeek);
	}

	private static function ready(string $appId): AutoUpdateMember {
		return new AutoUpdateMember($appId, '1.0.0', '1.0.1', null);
	}

	/**
	 * @param list<AutoUpdateMember> $members
	 *
	 * @return list<array{0: string, 1: ?string}>
	 */
	private function apply(array $members): array {
		$limiter = new AutoUpdateLimiter($this->settings, $this->ledger);

		return array_map(
			static fn (AutoUpdateMember $member): array => [$member->appId, $member->reason],
			$limiter->apply($members, $this->now)
		);
	}

	public function testWithoutACapAndAWaitingListThePlanIsUnchanged(): void {
		$this->caps(null, null);
		$this->settings->expects($this->never())->method('setDeferred');
		$members = [self::ready('zeta'), self::ready('alpha')];

		$this->assertSame([['zeta', null], ['alpha', null]], $this->apply($members));
	}

	public function testACapOfTwoHoldsTheThirdAppAndListsItAsWaiting(): void {
		$this->caps(2, null);

		$this->assertSame(
			[['alpha', null], ['beta', null], ['gamma', AutoUpdateMember::REASON_LIMIT]],
			$this->apply([self::ready('gamma'), self::ready('alpha'), self::ready('beta')])
		);
		$this->assertSame(['app:gamma' => '2026-11-15T02:00:00+00:00'], $this->deferred);
	}

	public function testAHeldAppGoesFirstInTheNextRunAndLeavesTheList(): void {
		$this->caps(1, null);
		$this->deferred = ['app:zeta' => '2026-11-14T02:00:00+00:00'];

		$this->assertSame(
			[['zeta', null], ['alpha', AutoUpdateMember::REASON_LIMIT]],
			$this->apply([self::ready('alpha'), self::ready('zeta')])
		);
		$this->assertSame(['app:alpha' => '2026-11-15T02:00:00+00:00'], $this->deferred);
	}

	public function testTheOldestHeldUnitGoesFirstAndKeepsItsFirstHeldTime(): void {
		$this->caps(1, null);
		$this->deferred = [
			'app:newer' => '2026-11-14T02:00:00+00:00',
			'app:older' => '2026-11-10T02:00:00+00:00',
		];

		$this->assertSame(
			[['older', null], ['newer', AutoUpdateMember::REASON_LIMIT]],
			$this->apply([self::ready('newer'), self::ready('older')])
		);
		$this->assertSame(['app:newer' => '2026-11-14T02:00:00+00:00'], $this->deferred);
	}

	public function testTheWeeklyCapCountsLastWeeksAttempts(): void {
		$this->settings->method('getMaxPerRun')->willReturn(10);
		$this->settings->method('getMaxPerWeek')->willReturn(5);
		$this->ledger->expects($this->once())->method('countSince')->with('2026-11-08T02:00:00+00:00')->willReturn(4);

		$this->assertSame(
			[['alpha', null], ['beta', AutoUpdateMember::REASON_LIMIT]],
			$this->apply([self::ready('alpha'), self::ready('beta')])
		);
	}

	public function testASpentWeekHoldsEverythingAndOthersPassThrough(): void {
		$this->caps(null, 3, 7);
		$current = new AutoUpdateMember('current', '1.0.0', null, AutoUpdateMember::REASON_NO_CANDIDATE);
		$pinned = new AutoUpdateMember('pinned', null, null, AutoUpdateMember::REASON_PINNED);
		$this->deferred = ['app:current' => '2026-11-01T02:00:00+00:00', 'app:pinned' => '2026-11-02T02:00:00+00:00'];

		$this->assertSame(
			[['alpha', AutoUpdateMember::REASON_LIMIT], ['current', AutoUpdateMember::REASON_NO_CANDIDATE], ['pinned', AutoUpdateMember::REASON_PINNED]],
			$this->apply([self::ready('alpha'), $current, $pinned])
		);
		// A unit without a candidate leaves the list; a pinned one keeps its place.
		$this->assertSame(['app:pinned' => '2026-11-02T02:00:00+00:00', 'app:alpha' => '2026-11-15T02:00:00+00:00'], $this->deferred);
	}

	public function testTheBudgetIsTheSmallerOfTheTwoCaps(): void {
		$limiter = new AutoUpdateLimiter($this->settings, $this->ledger);
		$this->caps(3, 10, 8);

		$this->assertSame(2, $limiter->budget($this->now));
	}
}
