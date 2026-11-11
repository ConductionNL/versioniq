<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class AdvisorySettingsStoreTest extends TestCase {
	/** @var array<string, int|bool> */
	private array $stored = [];

	private function store(): AdvisorySettingsStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->stored[$key] ?? $default),
		);
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->stored[$key] = $value;

				return true;
			},
		);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (bool)($this->stored[$key] ?? $default),
		);
		$config->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->stored[$key] = $value;

				return true;
			},
		);

		return new AdvisorySettingsStore($config);
	}

	public function testDefaultsToSixHours(): void {
		$this->assertSame(6, $this->store()->getIntervalHours());
		$this->assertSame(6 * 3600, $this->store()->getIntervalSeconds());
	}

	public function testStoresAndReadsBackAnIntervalInRange(): void {
		$store = $this->store();
		$store->setIntervalHours(12);

		$this->assertSame(12, $store->getIntervalHours());
		$this->assertSame(12 * 3600, $store->getIntervalSeconds());
	}

	/**
	 * A value outside the range must still yield a WORKING schedule. Refusing
	 * to run because a stored number is out of bounds would silently stop
	 * security checks — worse than checking at a neighbouring frequency.
	 *
	 * @dataProvider outOfRangeValues
	 */
	public function testClampsRatherThanRefusing(int $requested, int $expected): void {
		$store = $this->store();
		$store->setIntervalHours($requested);

		$this->assertSame($expected, $store->getIntervalHours());
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function outOfRangeValues(): array {
		return [
			'below the floor' => [0, 1],
			'negative' => [-5, 1],
			'above the ceiling' => [48, 24],
			'absurd' => [100000, 24],
			'at the floor' => [1, 1],
			'at the ceiling' => [24, 24],
		];
	}

	/**
	 * A value written by `occ config:app:set`, bypassing the API's validation,
	 * must still read back as something schedulable.
	 */
	public function testClampsAValueThatArrivedOutsideTheApi(): void {
		$this->stored[AdvisorySettingsStore::CONFIG_INTERVAL_HOURS] = 999;

		$this->assertSame(24, $this->store()->getIntervalHours());
	}

	/**
	 * Defaults ON: the urgent path notifies regardless, and the digest is what
	 * carries everything else. Defaulting it off would hide that material
	 * behind a setting nobody knows exists.
	 */
	public function testTheDigestDefaultsToEnabled(): void {
		$this->assertTrue($this->store()->isDigestEnabled());
	}

	public function testTheDigestCanBeTurnedOffAndBackOn(): void {
		$store = $this->store();

		$store->setDigestEnabled(false);
		$this->assertFalse($store->isDigestEnabled());

		$store->setDigestEnabled(true);
		$this->assertTrue($store->isDigestEnabled());
	}
}
