<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\FirstSeenStore;
use OCA\Versioniq\Service\AutoUpdate\MinimumAgeRule;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\AutoUpdate\MinimumAgeRule
 * @covers \OCA\Versioniq\Service\AutoUpdate\FirstSeenStore
 */
class MinimumAgeRuleTest extends TestCase {
	/** @var array<string, string> In-memory app config. */
	private array $stored = [];

	private function store(): FirstSeenStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;
			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->stored[$key]);
		});

		return new FirstSeenStore($config);
	}

	private static function at(string $when): \DateTimeImmutable {
		return new \DateTimeImmutable($when);
	}

	public function testADatedVersionIsHeldUntilItIsOldEnough(): void {
		$rule = new MinimumAgeRule($this->store());
		$entry = ['version' => '2.0.0', 'releasedAt' => '2026-11-10T08:00:00+00:00'];

		$this->assertTrue($rule->isHeld('mail', $entry, 7, self::at('2026-11-14T08:00:00+00:00')));
		$this->assertFalse($rule->isHeld('mail', $entry, 7, self::at('2026-11-17T08:00:00+00:00')));
		$this->assertEquals(self::at('2026-11-17T08:00:00+00:00'), MinimumAgeRule::eligibleAt(self::at('2026-11-10T08:00:00+00:00'), 7));
		$this->assertSame([], $this->stored, 'a dated version records no first-seen time');
	}

	public function testAnUndatedVersionSeenDaysAgoCountsFromThen(): void {
		$this->stored[FirstSeenStore::KEY_PREFIX . 'mail'] = json_encode(['2.0.0' => '2026-11-01T00:00:00+00:00']);
		$rule = new MinimumAgeRule($this->store());

		$this->assertEquals(self::at('2026-11-01T00:00:00+00:00'), $rule->releasedAt('mail', ['version' => '2.0.0'], self::at('2026-11-14T00:00:00+00:00')));
		$this->assertFalse($rule->isHeld('mail', ['version' => '2.0.0', 'releasedAt' => null], 7, self::at('2026-11-14T00:00:00+00:00')));
	}

	public function testAnUndatedVersionSeenNowIsHeldAndRecorded(): void {
		$rule = new MinimumAgeRule($this->store());
		$now = self::at('2026-11-14T09:30:00+01:00');

		$this->assertTrue($rule->isHeld('mail', ['version' => '2.1.0'], 3, $now));
		$this->assertSame(['2.1.0' => '2026-11-14T08:30:00+00:00'], json_decode($this->stored[FirstSeenStore::KEY_PREFIX . 'mail'], true));
		$this->assertTrue($rule->isHeld('mail', ['version' => '2.1.0'], 3, self::at('2026-11-16T08:30:00+00:00')), 'the first sighting stays the clock');
	}

	public function testAgeZeroHoldsNothingAndRecordsNothing(): void {
		$rule = new MinimumAgeRule($this->store());

		$this->assertFalse($rule->isHeld('mail', ['version' => '3.0.0'], 0, self::at('2026-11-14T00:00:00+00:00')));
		$this->assertSame([], $this->stored);
	}

	public function testVersionsNoLongerListedArePruned(): void {
		$this->stored[FirstSeenStore::KEY_PREFIX . 'mail'] = json_encode(['1.0.0' => '2026-01-01T00:00:00+00:00', '2.0.0' => '2026-02-01T00:00:00+00:00']);
		$store = $this->store();

		$store->prune('mail', ['2.0.0', '3.0.0']);
		$this->assertSame(['2.0.0' => '2026-02-01T00:00:00+00:00'], $store->all('mail'));

		$store->prune('mail', []);
		$this->assertArrayNotHasKey(FirstSeenStore::KEY_PREFIX . 'mail', $this->stored);
	}
}
