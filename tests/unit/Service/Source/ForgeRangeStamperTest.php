<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inventory-upgrade-readiness task 1.2: listed forge versions take the range
 * of their info.xml at the tag, read once and stored, and a cached archive wins.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Source\ForgeRangeStamper;
use OCA\Versioniq\Service\Source\ForgeRangeStore;
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Source\ForgeRangeStamper
 * @uses \OCA\Versioniq\Service\Source\ForgeRangeStore
 * @uses \OCA\Versioniq\Service\Source\InfoXmlRanges
 * @uses \OCA\Versioniq\Service\Source\AppStoreSource
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 */
final class ForgeRangeStamperTest extends TestCase {
	private const RUNNING = ['server' => '32.0.1', 'php' => '8.2.10'];

	/** @var array<string, string> */
	private array $values = [];

	/** @var ForgeReleaseSource&MockObject */
	private ForgeReleaseSource $forge;

	protected function setUp(): void {
		$this->forge = $this->createMock(ForgeReleaseSource::class);
	}

	private function stamper(): ForgeRangeStamper {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->values[$key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$key] = $value;
				return true;
			}
		);

		return new ForgeRangeStamper(new ForgeRangeStore($config), $this->forge);
	}

	/**
	 * Stamp a forge listing and give it this server's verdicts, as the installer does.
	 *
	 * @param list<array<string, mixed>> $listing
	 * @return list<array<string, mixed>>
	 */
	private function stamp(array $listing): array {
		return ForgeRangeStamper::withVerdicts($this->stamper()->stamp($listing, self::binding()), self::RUNNING);
	}

	private static function binding(): SourceBinding {
		return SourceBinding::github('ConductionNL', 'hermiq');
	}

	private static function infoXml(string $nextcloud, string $php = ''): string {
		return '<info><dependencies>' . $php . '<nextcloud ' . $nextcloud . '/></dependencies></info>';
	}

	public function testAnUncachedReleaseShowsWhetherItRunsHereAndIsReadOnce(): void {
		$this->forge->expects($this->once())->method('infoXmlAtTag')
			->with(self::binding(), '1.4.0')
			->willReturn(self::infoXml('min-version="31" max-version="33"', '<php min-version="8.3"/>'));

		$listing = [['version' => '1.4.0', 'changelog' => null, 'cachedOffline' => false]];
		$first = $this->stamp($listing);
		$again = $this->stamp($listing);

		$expected = [[
			'version' => '1.4.0',
			'changelog' => null,
			'cachedOffline' => false,
			'platformSpec' => '>=31 <=33',
			'phpSpec' => '>=8.3',
			'serverCompatible' => true,
			'phpCompatible' => false,
		]];
		$this->assertSame($expected, $first);
		$this->assertSame($expected, $again);
	}

	public function testAMissingInfoXmlIsStoredAsNoRangeAndNotFetchedAgain(): void {
		$this->forge->expects($this->once())->method('infoXmlAtTag')->willReturn('');

		$listing = [['version' => '1.4.0']];
		$first = $this->stamp($listing);
		$again = $this->stamp($listing);

		$expected = [['version' => '1.4.0', 'platformSpec' => null, 'phpSpec' => null, 'serverCompatible' => null, 'phpCompatible' => null]];
		$this->assertSame($expected, $first);
		$this->assertSame($expected, $again);
	}

	public function testAFailedRequestIsNotStoredSoItIsAskedAgain(): void {
		$this->forge->expects($this->exactly(2))->method('infoXmlAtTag')->willReturn(null);

		$listing = [['version' => '1.4.0']];
		$this->assertSame($listing, $this->stamp($listing));
		$this->assertSame($listing, $this->stamp($listing));
		$this->assertSame([], $this->values);
	}

	public function testACachedArchiveStillWins(): void {
		$this->forge->expects($this->never())->method('infoXmlAtTag');

		$listing = [['version' => '1.4.0', 'cachedOffline' => true, 'serverCompatible' => false]];

		$this->assertSame($listing, $this->stamp($listing));
	}

	public function testAtMostFiveVersionsAreReadPerListingNewestFirst(): void {
		$asked = [];
		$this->forge->method('infoXmlAtTag')->willReturnCallback(
			function (SourceBinding $binding, string $version) use (&$asked): string {
				$asked[] = $version;
				return self::infoXml('min-version="30" max-version="31"');
			}
		);

		$listing = array_map(static fn (int $minor): array => ['version' => '1.' . $minor . '.0'], [7, 6, 5, 4, 3, 2, 1]);
		$stamped = $this->stamp($listing);

		$this->assertSame(['1.7.0', '1.6.0', '1.5.0', '1.4.0', '1.3.0'], $asked);
		$this->assertFalse($stamped[0]['serverCompatible']);
		$this->assertSame(['version' => '1.2.0'], $stamped[5]);
		$this->assertSame(['version' => '1.1.0'], $stamped[6]);

		// The next listing reads the next five: the first five are stored.
		$asked = [];
		$this->stamp($listing);
		$this->assertSame(['1.2.0', '1.1.0'], $asked);
	}

	public function testAVerdictIsOnlyAddedWhereASpecIsAndNoneWasGiven(): void {
		$listing = [
			['version' => '3', 'platformSpec' => '>=33', 'phpSpec' => null],
			['version' => '2', 'platformSpec' => '>=30', 'serverCompatible' => null, 'phpSpec' => '>=8.1', 'phpCompatible' => false],
			['version' => '1'],
		];

		$this->assertSame(
			[
				['version' => '3', 'platformSpec' => '>=33', 'phpSpec' => null, 'serverCompatible' => false, 'phpCompatible' => null],
				['version' => '2', 'platformSpec' => '>=30', 'serverCompatible' => null, 'phpSpec' => '>=8.1', 'phpCompatible' => false],
				['version' => '1'],
			],
			ForgeRangeStamper::withVerdicts($listing, self::RUNNING)
		);
	}

	public function testAValueTheListingAlreadyHasStays(): void {
		$this->forge->method('infoXmlAtTag')->willReturn(self::infoXml('min-version="31" max-version="33"'));

		$stamped = $this->stamp([['version' => '1.4.0', 'serverCompatible' => false]]);

		$this->assertFalse($stamped[0]['serverCompatible']);
		$this->assertSame('>=31 <=33', $stamped[0]['platformSpec']);
	}

	public function testAnAppStoreListingIsLeftAlone(): void {
		$this->forge->expects($this->never())->method('infoXmlAtTag');
		$listing = [['version' => '2.0.0']];

		$this->assertSame($listing, ForgeRangeStamper::withVerdicts($this->stamper()->stamp($listing, SourceBinding::appStore()), self::RUNNING));
	}
}
