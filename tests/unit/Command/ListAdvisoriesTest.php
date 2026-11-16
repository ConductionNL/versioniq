<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\ListAdvisories;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Advisory\FixStatus;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ versioniq:advisories` (advisories-per-app-detail D5).
 *
 * @spec openspec/changes/advisories-per-app-detail/specs/cli-commands/spec.md#requirement-read-advisories-and-the-compliance-status-from-the-cli
 */
final class ListAdvisoriesTest extends TestCase {
	private const NOW = 1_790_000_600;
	private const CHECKED = 1_790_000_000;

	private static function history(string $id, ?string $date, bool $affects, array $cves = []): array {
		return ['id' => $id, 'severity' => 'medium', 'summary' => '', 'cveIds' => $cves, 'publishedAt' => $date, 'url' => null, 'affectsInstalled' => $affects];
	}

	private static function affected(): array {
		return [
			'fixtureapp' => [
				'appId' => 'fixtureapp',
				'installedVersion' => '1.0.0',
				'state' => AdvisoryService::STATE_VULNERABLE,
				'advisories' => [['id' => 'GHSA-new', 'severity' => 'medium', 'summary' => '']],
				'recommendedVersion' => '1.0.1',
				'error' => null,
				'fixStatus' => FixStatus::INSTALLABLE,
				'history' => [
					self::history('GHSA-new', '2026-08-01T00:00:00Z', true, ['CVE-2026-1111']),
					self::history('GHSA-old', '2025-11-02T00:00:00Z', false),
				],
			],
			'deck' => [
				'appId' => 'deck',
				'installedVersion' => '1.14.0',
				'state' => AdvisoryService::STATE_AVAILABLE,
				'advisories' => [],
				'recommendedVersion' => null,
				'error' => null,
				'fixStatus' => null,
				'history' => [self::history('GHSA-deck', null, false)],
			],
		];
	}

	private static function compliant(): array {
		$rows = self::affected();
		$rows['fixtureapp']['state'] = AdvisoryService::STATE_AVAILABLE;
		$rows['fixtureapp']['fixStatus'] = null;

		return $rows;
	}

	private function tester(array $advisories, ?int $checkedAt, array $pins = []): CommandTester {
		$store = $this->createMock(AdvisoryResultStore::class);
		$store->method('read')->willReturn(['advisories' => $advisories, 'checkedAt' => $checkedAt]);
		$settings = $this->createMock(AdvisorySettingsStore::class);
		$settings->method('getIntervalHours')->willReturn(6);
		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('all')->willReturn($pins);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new CommandTester(new ListAdvisories($store, $settings, $pinStore, $time));
	}

	public function testCheckExitsZeroAndLeadsWithTheCompliantStatus(): void {
		$tester = $this->tester(self::compliant(), self::CHECKED);

		self::assertSame(ListAdvisories::EXIT_COMPLIANT, $tester->execute(['--check' => true]));
		self::assertStringStartsWith('Compliant', $tester->getDisplay());
	}

	public function testCheckExitsOneWhenAnInstalledVersionIsAffected(): void {
		$tester = $this->tester(self::affected(), self::CHECKED);

		self::assertSame(ListAdvisories::EXIT_NOT_COMPLIANT, $tester->execute(['--check' => true]));
		$display = $tester->getDisplay();
		self::assertStringStartsWith('Not compliant: 1 app runs an affected version (1 medium).', $display);
		self::assertStringContainsString('CVE-2026-1111', $display);
		self::assertStringContainsString('2026-08-01', $display);
		self::assertStringContainsString('affected - fix 1.0.1', $display);
		self::assertStringContainsString('date unknown', $display);
		self::assertStringContainsString('no CVE id', $display);
	}

	public function testCheckExitsTwoWhenNeverChecked(): void {
		$tester = $this->tester([], null);

		self::assertSame(ListAdvisories::EXIT_UNKNOWN, $tester->execute(['--check' => true]));
		self::assertStringStartsWith('Unknown: no advisory check has completed yet.', $tester->getDisplay());
	}

	public function testWithoutCheckItExitsZeroWheneverItPrinted(): void {
		self::assertSame(0, $this->tester(self::affected(), self::CHECKED)->execute([]));
		self::assertSame(0, $this->tester([], null)->execute([]));
	}

	public function testJsonCarriesComplianceCheckedAtAndTargets(): void {
		$tester = $this->tester(self::affected(), self::CHECKED, ['fixtureapp' => new Pin('1.0.0', 'admin', '2026-11-01T00:00:00+00:00')]);

		self::assertSame(0, $tester->execute(['--json' => true]));
		$decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

		self::assertSame(['compliance', 'checkedAt', 'targets'], array_keys($decoded));
		self::assertSame('not_compliant', $decoded['compliance']['status']);
		self::assertSame(self::CHECKED, $decoded['checkedAt']);
		self::assertSame(['fixtureapp', 'deck'], array_keys($decoded['targets']));
		self::assertTrue($decoded['targets']['fixtureapp']['heldByPin']);
		self::assertSame('GHSA-new', $decoded['targets']['fixtureapp']['history'][0]['id']);
	}

	public function testAppLimitsTheOutputToOneTargetButNotTheStatus(): void {
		$tester = $this->tester(self::affected(), self::CHECKED);

		self::assertSame(ListAdvisories::EXIT_NOT_COMPLIANT, $tester->execute(['--json' => true, '--app' => 'deck', '--check' => true]));
		$decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(['deck'], array_keys($decoded['targets']));
		self::assertSame('not_compliant', $decoded['compliance']['status']);
	}

	public function testTheStatusLineNamesEachUnknownReason(): void {
		self::assertSame('Unknown: the last advisory check is out of date.', ListAdvisories::statusLine(['status' => 'unknown', 'reason' => 'stale', 'affected' => 0, 'bySeverity' => [], 'unreached' => 0]));
		self::assertSame('Unknown: 2 apps could not be checked.', ListAdvisories::statusLine(['status' => 'unknown', 'reason' => 'unreached', 'affected' => 0, 'bySeverity' => [], 'unreached' => 2]));
		self::assertSame('Not compliant: 2 apps run an affected version (1 critical, 1 medium).', ListAdvisories::statusLine(['status' => 'not_compliant', 'reason' => null, 'affected' => 2, 'bySeverity' => ['critical' => 1, 'medium' => 1], 'unreached' => 0]));
	}
}
