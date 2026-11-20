<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\AutoUpdate\AgeQuery;
use OCA\Versioniq\Service\AutoUpdate\PlanTarget;
use OCA\Versioniq\Service\AutoUpdate\MinimumAgePlanner;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\FirstSeenStore;
use OCA\Versioniq\Service\AutoUpdate\MinAgeExceptionStore;
use OCA\Versioniq\Service\AutoUpdate\MinimumAgeRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The plan of one app: the newest eligible version, the versions held back
 * by the minimum age, an admin's exception and a security fix let through
 * (releases-minimum-age 2.1).
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 */
#[CoversClass(MinimumAgePlanner::class)]
#[UsesClass(CandidateSelector::class)]
#[UsesClass(MinimumAgeRule::class)]
final class MinimumAgePlannerTest extends TestCase {
	private const NOW = '2026-11-14T12:00:00+00:00';

	private MinAgeExceptionStore&MockObject $exceptions;
	private AdvisoryResultStore&MockObject $advisories;
	private AuditLogger&MockObject $audit;
	/** @var array{version: string, by: string, at: string}|null */
	private ?array $exception = null;
	/** @var list<string> */
	private array $auditMessages = [];

	protected function setUp(): void {
		parent::setUp();
		$this->exceptions = $this->createMock(MinAgeExceptionStore::class);
		$this->exceptions->method('get')->willReturnCallback(fn (): ?array => $this->exception);
		$this->exceptions->method('remove')->willReturnCallback(function (): void {
			$this->exception = null;
		});
		$this->advisories = $this->createMock(AdvisoryResultStore::class);
		$this->advisories->method('read')->willReturn(['advisories' => [], 'checkedAt' => null]);
		$this->audit = $this->createMock(AuditLogger::class);
		$this->audit->method('record')->willReturnCallback(
			function (AuditSubject $subject, AuditedVersions $versions, string $status, string $message): void {
				$this->auditMessages[] = $subject->operation . '|' . $subject->appId . '|' . $message;
			},
		);
	}

	private function planner(): MinimumAgePlanner {
		$firstSeen = $this->createMock(FirstSeenStore::class);
		$firstSeen->method('firstSeen')->willReturn(new \DateTimeImmutable(self::NOW));

		return new MinimumAgePlanner(new CandidateSelector(), new MinimumAgeRule($firstSeen), $this->exceptions, $this->advisories, $this->audit);
	}

	private static function daysAgo(int $days): string {
		return (new \DateTimeImmutable(self::NOW))->modify('-' . $days . ' days')->format(DATE_ATOM);
	}

	/**
	 * @param array<string, int> $ages version => days since release
	 * @return list<array{version: string, releasedAt: string}>
	 */
	private static function entries(array $ages): array {
		$entries = [];
		foreach ($ages as $version => $days) {
			$entries[] = ['version' => (string)$version, 'releasedAt' => self::daysAgo($days)];
		}

		return $entries;
	}

	/**
	 * @param array<string, int> $ages
	 * @return array<string, mixed>
	 */
	private function plan(array $ages, string $level = 'minor', int $minAgeDays = 7, string $installed = '1.0.0'): array {
		return $this->planner()->plan(AgeQuery::of(new PlanTarget('deck', $level, $minAgeDays, new \DateTimeImmutable(self::NOW)), $installed, self::entries($ages)));
	}

	private function vulnerable(string $recommended): void {
		$this->advisories = $this->createMock(AdvisoryResultStore::class);
		$this->advisories->method('read')->willReturn(['advisories' => ['deck' => [
			'appId' => 'deck', 'installedVersion' => '1.0.0', 'state' => 'pinned-to-vulnerable',
			'advisories' => [['id' => 'GHSA-aaaa', 'severity' => 'high', 'summary' => 'x'], ['id' => 'GHSA-bbbb', 'severity' => 'low', 'summary' => 'y']],
			'recommendedVersion' => $recommended, 'error' => null,
		]], 'checkedAt' => 1]);
	}

	public function testTheNewestEligibleVersionIsTakenWhileANewerOneWaits(): void {
		$plan = $this->plan(['1.1.0' => 30, '1.2.0' => 10, '1.3.0' => 2, '2.0.0' => 40]);

		self::assertSame('1.2.0', $plan['next']);
		self::assertSame([[
			'version' => '1.3.0', 'releasedAt' => self::daysAgo(2), 'eligibleAt' => '2026-11-19T12:00:00+00:00', 'reason' => 'minimum_age',
		]], $plan['heldBack']);
		self::assertNull($plan['securityBypass']);
		self::assertNull($plan['exception']);
	}

	public function testNothingIsEligibleWhileEveryNewerVersionWaits(): void {
		$plan = $this->plan(['1.1.0' => 3, '1.2.0' => 1]);

		self::assertNull($plan['next']);
		self::assertSame(['1.1.0', '1.2.0'], array_column($plan['heldBack'], 'version'));
	}

	public function testAMinimumAgeOfZeroHoldsNothing(): void {
		$plan = $this->plan(['1.1.0' => 0, '1.2.0' => 0], 'minor', 0);

		self::assertSame('1.2.0', $plan['next']);
		self::assertSame([], $plan['heldBack']);
	}

	public function testAnExceptionMakesItsVersionEligible(): void {
		$this->exception = ['version' => '1.3.0', 'by' => 'admin', 'at' => self::NOW];

		$plan = $this->plan(['1.2.0' => 10, '1.3.0' => 2, '1.4.0' => 1]);

		self::assertSame('1.3.0', $plan['next']);
		self::assertSame('1.3.0', $plan['exception']);
		self::assertSame(['1.4.0'], array_column($plan['heldBack'], 'version'));
		self::assertSame([], $this->auditMessages);
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, int>, 2: string, 3: string}>
	 */
	public static function spentExceptions(): array {
		return [
			'installed' => ['1.3.0', ['1.3.0' => 2, '1.4.0' => 1], '1.3.0', 'Minimum-age exception for 1.3.0 removed: version 1.3.0 is installed'],
			'unlisted' => ['1.3.0', ['1.2.0' => 10, '1.4.0' => 1], '1.0.0', 'Minimum-age exception for 1.3.0 removed: the source no longer lists it'],
			'old enough' => ['1.3.0', ['1.3.0' => 8, '1.4.0' => 1], '1.0.0', 'Minimum-age exception for 1.3.0 removed: it reached the minimum age'],
		];
	}

	/**
	 * @dataProvider spentExceptions
	 * @param array<string, int> $ages
	 */
	public function testASpentExceptionIsRemovedAndAudited(string $version, array $ages, string $installed, string $message): void {
		$this->exception = ['version' => $version, 'by' => 'admin', 'at' => self::NOW];
		$this->exceptions->expects($this->once())->method('remove')->with('deck');

		$plan = $this->plan($ages, 'minor', 7, $installed);

		self::assertNull($plan['exception']);
		self::assertSame(['settings|deck|' . $message], $this->auditMessages);
		self::assertSame(['1.4.0'], array_column($plan['heldBack'], 'version'));
	}

	public function testASecurityFixInsideTheLevelSkipsTheHoldWhileNewerVersionsStillWait(): void {
		$this->vulnerable('1.2.1');

		$plan = $this->plan(['1.2.1' => 1, '1.2.2' => 1, '1.3.0' => 0], 'minor');

		self::assertSame('1.2.1', $plan['next']);
		self::assertSame(['version' => '1.2.1', 'advisories' => ['GHSA-aaaa', 'GHSA-bbbb'], 'reason' => 'security_fix'], $plan['securityBypass']);
		self::assertSame(['1.2.2', '1.3.0'], array_column($plan['heldBack'], 'version'));
	}

	public function testASecurityFixOutsideTheLevelIsNotInstalledAndThePlanSaysWhy(): void {
		$this->vulnerable('1.1.0');

		$plan = $this->plan(['1.0.1' => 30, '1.1.0' => 1], 'patch');

		self::assertSame('1.0.1', $plan['next']);
		self::assertSame(['version' => '1.1.0', 'advisories' => ['GHSA-aaaa', 'GHSA-bbbb'], 'reason' => 'outside_level'], $plan['securityBypass']);
		self::assertSame([], $plan['heldBack']);
	}

	public function testASecurityFixThatNeedsNoBypassIsNotReported(): void {
		$this->vulnerable('1.2.0');

		$plan = $this->plan(['1.2.0' => 30, '1.3.0' => 1]);

		self::assertSame('1.2.0', $plan['next']);
		self::assertNull($plan['securityBypass']);
	}
}
