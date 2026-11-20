<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inventory-upgrade-readiness task 1.2: a forge release's range is kept per
 * source and version, a release with no range included.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Source\ForgeRangeStore;
use OCA\Versioniq\Service\Source\InfoXmlRanges;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Source\ForgeRangeStore
 * @uses \OCA\Versioniq\Service\Source\InfoXmlRanges
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 */
final class ForgeRangeStoreTest extends TestCase {
	/** @var array<string, string> */
	private array $values = [];

	private function store(): ForgeRangeStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->values[$app . '/' . $key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$app . '/' . $key] = $value;
				return true;
			}
		);

		return new ForgeRangeStore($config);
	}

	private static function release(string $repo, string $version): string {
		return ForgeRangeStore::release(SourceBinding::github('ConductionNL', $repo), $version);
	}

	public function testTheReleaseNameIsTheSourceIdAndTheVersion(): void {
		$this->assertSame("github:ConductionNL/hermiq\n1.4.0", self::release('hermiq', '1.4.0'));
	}

	public function testANeverReadReleaseHasNoStoredRange(): void {
		$this->assertNull($this->store()->find(self::release('hermiq', '1.4.0')));
	}

	public function testASavedRangeIsFoundForThatSourceAndVersionOnly(): void {
		$store = $this->store();
		$store->save(self::release('hermiq', '1.4.0'), InfoXmlRanges::fromArray(['nextcloudMin' => '31', 'nextcloudMax' => '33', 'phpMin' => '8.1']));

		$this->assertSame('>=31 <=33', $store->find(self::release('hermiq', '1.4.0'))?->platformSpec());
		$this->assertSame('>=8.1', $store->find(self::release('hermiq', '1.4.0'))?->phpSpec());
		$this->assertNull($store->find(self::release('hermiq', '1.3.0')));
		$this->assertNull($store->find(self::release('other', '1.4.0')));
	}

	public function testANoRangeAnswerIsStoredAsFoundWithoutBounds(): void {
		$store = $this->store();
		$store->save(self::release('hermiq', '1.4.0'), InfoXmlRanges::fromArray([]));

		$found = $store->find(self::release('hermiq', '1.4.0'));
		$this->assertNotNull($found);
		$this->assertSame('', $found->platformSpec());
	}

	public function testKeysFitTheAppConfigLimitWhateverTheSource(): void {
		$this->store()->save(self::release(str_repeat('r', 80), '10.20.30-beta.1'), InfoXmlRanges::fromArray([]));

		$key = array_key_first($this->values);
		$this->assertStringStartsWith('versioniq/forge_range.', $key);
		$this->assertLessThanOrEqual(64, strlen(substr($key, strlen('versioniq/'))));
	}

	public function testAValueThatIsNotJsonReadsAsNeverRead(): void {
		$store = $this->store();
		$store->save(self::release('b', '1.0.0'), InfoXmlRanges::fromArray(['nextcloudMin' => '30']));
		$this->values[array_key_first($this->values)] = '{broken';

		$this->assertNull($store->find(self::release('b', '1.0.0')));
	}
}
