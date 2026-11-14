<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-a-withdrawn-installed-version-is-marked-and-never-reinstalled-by-accident
 */

namespace OCA\Versioniq\Tests\Unit\Service\Availability;

use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCA\Versioniq\Service\Availability\WithdrawnVersionStore;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Withdrawn versions (safety-withdrawn-and-revoked, design D3): the record kept
 * from successful listings, and the sweep's `installedWithdrawn`.
 *
 * @covers \OCA\Versioniq\Service\Availability\WithdrawnVersionStore
 * @covers \OCA\Versioniq\Service\Availability\AvailabilityService
 * @uses   \OCA\Versioniq\Service\Availability\AppVersionCheck
 */
final class WithdrawnVersionsTest extends TestCase {
	/** @var array<string, string> */
	private array $stored = [];

	/** @var array<string, mixed> */
	private array $listings = [];

	private function store(): WithdrawnVersionStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default,
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;

				return true;
			},
		);

		return new WithdrawnVersionStore($config);
	}

	private function sweep(string $installed): array {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([['id' => 'notes', 'installedVersion' => $installed, 'state' => 'enabled']]);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->method('getAppVersions')->willReturnCallback(function (): array {
			if ($this->listings['notes'] instanceof \Throwable) {
				throw $this->listings['notes'];
			}

			return $this->listings['notes'];
		});

		return (new AvailabilityService($installer, $this->createMock(PinStore::class), new NullLogger(), $this->store()))->sweep(60.0)['notes'];
	}

	/**
	 * @param list<string> $versions
	 */
	private static function listing(array $versions, bool $failed = false): array {
		return ['availableVersions' => array_map(static fn (string $v): array => ['version' => $v], $versions), 'hasError' => $failed, 'error' => $failed ? 'HTTP 503' : null, 'sourceId' => 'appstore'];
	}

	public function testTheFirstListingOnlySetsTheBaseline(): void {
		$this->assertSame([], $this->store()->recordListing('notes', ['1.0.0', '1.1.0']));
		$this->assertSame([], $this->store()->withdrawnVersions('notes'));
	}

	public function testAVersionALaterListingDropsIsWithdrawnAndOneThatReturnsIsNot(): void {
		$this->store()->recordListing('notes', ['1.0.0', '1.1.0', '1.2.0']);

		$this->assertSame(['1.1.0'], $this->store()->recordListing('notes', ['1.0.0', '1.2.0']));
		$this->assertTrue($this->store()->isWithdrawn('notes', '1.1.0'));
		$this->assertFalse($this->store()->isWithdrawn('notes', '1.2.0'));
		$this->assertFalse($this->store()->isWithdrawn('mail', '1.1.0'));

		$this->store()->recordListing('notes', ['1.0.0', '1.1.0', '1.2.0']);
		$this->assertSame([], $this->store()->withdrawnVersions('notes'));
	}

	public function testTheWithdrawnListKeepsTheNewestFifty(): void {
		$all = array_map(static fn (int $n): string => '1.0.' . $n, range(0, 59));
		$this->store()->recordListing('notes', $all);
		$this->store()->recordListing('notes', ['2.0.0']);

		$withdrawn = $this->store()->withdrawnVersions('notes');
		$this->assertCount(WithdrawnVersionStore::MAX_WITHDRAWN, $withdrawn);
		$this->assertSame('1.0.59', end($withdrawn));
		$this->assertNotContains('1.0.9', $withdrawn);
	}

	public function testUnreadableStoredListsReadAsNothing(): void {
		$this->stored['withdrawn.notes'] = '{"a":1}';
		$this->stored['availability.listed.notes'] = 'not json';

		$this->assertSame([], $this->store()->withdrawnVersions('notes'));
		$this->assertSame([], $this->store()->recordListing('notes', ['1.0.0']), 'an unreadable baseline is no baseline');
	}

	public function testTheSweepMarksAnInstalledVersionThatASuccessfulListingNoLongerOffers(): void {
		$this->listings['notes'] = self::listing(['1.0.0', '1.1.0']);
		$this->assertFalse($this->sweep('1.1.0')['installedWithdrawn']);

		$this->listings['notes'] = self::listing(['1.0.0', '1.2.0']);
		$entry = $this->sweep('1.1.0');

		$this->assertTrue($entry['installedWithdrawn']);
		$this->assertSame(['1.1.0'], $this->store()->withdrawnVersions('notes'));
	}

	public function testAFailedListingNeverMarksAVersionWithdrawn(): void {
		$this->listings['notes'] = self::listing(['1.0.0', '1.1.0']);
		$this->sweep('1.1.0');

		$this->listings['notes'] = self::listing([], true);
		$this->assertNull($this->sweep('1.1.0')['installedWithdrawn']);

		$this->listings['notes'] = new \RuntimeException('source unreachable');
		$this->assertNull($this->sweep('1.1.0')['installedWithdrawn']);

		$this->assertSame([], $this->store()->withdrawnVersions('notes'));
		$this->assertSame('["1.0.0","1.1.0"]', $this->stored['availability.listed.notes'], 'the baseline is untouched');
	}

	public function testAnEmptyListingIsNotTakenAsEveryVersionWithdrawn(): void {
		$this->listings['notes'] = self::listing(['1.0.0', '1.1.0']);
		$this->sweep('1.1.0');

		$this->listings['notes'] = self::listing([]);
		$this->assertNull($this->sweep('1.1.0')['installedWithdrawn']);
		$this->assertSame([], $this->store()->withdrawnVersions('notes'));
	}

	public function testABuildTheSourceNeverListedIsNotCalledWithdrawn(): void {
		$this->listings['notes'] = self::listing(['1.0.0', '1.1.0']);

		$this->assertFalse($this->sweep('1.2.0-dev')['installedWithdrawn']);
	}
}
