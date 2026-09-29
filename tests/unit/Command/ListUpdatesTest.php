<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\ListUpdates;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ versioniq:updates` (inventory-pending-updates D6).
 *
 * @spec openspec/specs/cli-commands/spec.md
 */
final class ListUpdatesTest extends TestCase {
	private const SNAPSHOT = [
		'openregister' => ['installedVersion' => '2.3.0', 'newestVersion' => '3.0.0', 'newestCompatibleVersion' => '2.4.1', 'linesBehind' => 2, 'updateAvailable' => true, 'pinned' => true, 'sourceId' => 'appstore', 'error' => null],
		'calendar' => ['installedVersion' => '5.0.0', 'newestVersion' => '5.0.0', 'newestCompatibleVersion' => '5.0.0', 'linesBehind' => 0, 'updateAvailable' => false, 'pinned' => false, 'sourceId' => 'appstore', 'error' => null],
		'hermiq' => ['installedVersion' => '1.0.0', 'newestVersion' => null, 'newestCompatibleVersion' => null, 'linesBehind' => 0, 'updateAvailable' => false, 'pinned' => false, 'sourceId' => 'github:ConductionNL/hermiq', 'error' => 'HTTP 403'],
	];

	private function tester(array $read, ?AvailabilityService $service = null, ?AvailabilityResultStore $store = null, ?int $limit = null): CommandTester {
		if ($store === null) {
			$store = $this->createMock(AvailabilityResultStore::class);
			$store->method('read')->willReturn($read);
		}
		$settings = $this->createMock(InstanceSettings::class);
		$settings->method('maxLinesBehind')->willReturn($limit);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_600);

		return new CommandTester(new ListUpdates($service ?? $this->createMock(AvailabilityService::class), $store, $settings, $time));
	}

	public function testTheTableListsEveryAppWithItsVersionsLinesAndPin(): void {
		$tester = $this->tester(['updates' => self::SNAPSHOT, 'checkedAt' => 1_790_000_000]);

		self::assertSame(0, $tester->execute([]));
		$display = $tester->getDisplay();
		self::assertStringContainsString('openregister', $display);
		self::assertStringContainsString('2.4.1', $display);
		self::assertStringContainsString('pinned', $display);
		self::assertStringContainsString('not checked: HTTP 403', $display);
	}

	public function testJsonCarriesTheSnapshotTimeAndEachApp(): void {
		$tester = $this->tester(['updates' => self::SNAPSHOT, 'checkedAt' => 1_790_000_000]);

		self::assertSame(0, $tester->execute(['--json' => true]));
		$decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(1_790_000_000, $decoded['checkedAt']);
		self::assertSame('2.3.0', $decoded['updates']['openregister']['installedVersion']);
		self::assertSame('2.4.1', $decoded['updates']['openregister']['newestCompatibleVersion']);
		self::assertArrayHasKey('maxLinesBehind', $decoded);
	}

	public function testNeverCheckedExitsOneAndSaysSo(): void {
		$tester = $this->tester(['updates' => [], 'checkedAt' => null]);

		self::assertSame(1, $tester->execute([]));
		self::assertStringContainsString('No update check has run yet', $tester->getDisplay());
	}

	public function testRefreshSweepsAndStoresBeforePrinting(): void {
		$service = $this->createMock(AvailabilityService::class);
		$service->expects(self::once())->method('sweep')->willReturn(self::SNAPSHOT);
		$store = $this->createMock(AvailabilityResultStore::class);
		$store->expects(self::once())->method('save')->with(self::SNAPSHOT, 1_790_000_600);
		$store->method('read')->willReturn(['updates' => self::SNAPSHOT, 'checkedAt' => 1_790_000_600]);

		$tester = $this->tester([], $service, $store);

		self::assertSame(0, $tester->execute(['--refresh' => true, '--json' => true]));
		self::assertSame(1_790_000_600, json_decode($tester->getDisplay(), true)['checkedAt']);
	}

	public function testOutsidePolicyListsOnlyAppsPastTheLimit(): void {
		$tester = $this->tester(['updates' => self::SNAPSHOT, 'checkedAt' => 1_790_000_000], null, null, 1);

		self::assertSame(0, $tester->execute(['--outside-policy' => true, '--json' => true]));
		self::assertSame(['openregister'], array_keys(json_decode($tester->getDisplay(), true)['updates']));
	}
}
