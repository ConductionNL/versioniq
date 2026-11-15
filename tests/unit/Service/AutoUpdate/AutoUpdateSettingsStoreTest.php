<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class AutoUpdateSettingsStoreTest extends TestCase {
	public function testIsEnabledDefaultsToFalse(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')
			->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_ENABLED, false)
			->willReturn(false);

		$store = new AutoUpdateSettingsStore($config);

		$this->assertFalse($store->isEnabled());
	}

	public function testGetWindowDefaultsToTheStandardWindowWhenUnset(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(AutoUpdateWindow::DEFAULT_WINDOW);

		$store = new AutoUpdateSettingsStore($config);

		$this->assertSame('01:00-05:00', $store->getWindow());
	}

	public function testGetWindowFallsBackWhenStoredValueIsEmpty(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$store = new AutoUpdateSettingsStore($config);

		$this->assertSame(AutoUpdateWindow::DEFAULT_WINDOW, $store->getWindow());
	}

	public function testGetWindowReturnsAStoredCustomWindow(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('23:00-03:00');

		$store = new AutoUpdateSettingsStore($config);

		$this->assertSame('23:00-03:00', $store->getWindow());
	}

	public function testSetEnabledWritesTheConfigValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('setValueBool')
			->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_ENABLED, true);

		$store = new AutoUpdateSettingsStore($config);
		$store->setEnabled(true);
	}

	public function testSetWindowWritesTheConfigValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('setValueString')
			->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_WINDOW, '22:00-04:00');

		$store = new AutoUpdateSettingsStore($config);
		$store->setWindow('22:00-04:00');
	}

	public function testTimeZoneFollowsTheInstanceDefaultTimezone(): void {
		$system = $this->createMock(\OCP\IConfig::class);
		$system->method('getSystemValueString')->with('default_timezone', 'UTC')->willReturn('Europe/Amsterdam');

		$store = new AutoUpdateSettingsStore($this->createMock(IAppConfig::class), $system);

		$this->assertSame('Europe/Amsterdam', $store->getTimeZoneName());
	}

	public function testAnInvalidTimezoneFallsBackToUtc(): void {
		$system = $this->createMock(\OCP\IConfig::class);
		$system->method('getSystemValueString')->willReturn('Mars/Olympus');

		$store = new AutoUpdateSettingsStore($this->createMock(IAppConfig::class), $system);

		$this->assertSame('UTC', $store->getTimeZoneName());
	}

	public function testASweptWindowIsRemembered(): void {
		$stored = '';
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return $key === AutoUpdateSettingsStore::CONFIG_LAST_SWEPT_WINDOW ? $stored : $default;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored = $value;
				return true;
			}
		);

		$store = new AutoUpdateSettingsStore($config);

		$this->assertFalse($store->hasSweptWindow('01:00-05:00@2026-07-23'));
		$store->markWindowSwept('01:00-05:00@2026-07-23');
		$this->assertTrue($store->hasSweptWindow('01:00-05:00@2026-07-23'));
		$this->assertFalse($store->hasSweptWindow('01:00-05:00@2026-07-24'));
	}

	public function testCapsReadAsNullWhenAbsentOrOutOfRange(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(static fn (string $app, string $key): int => match ($key) {
			AutoUpdateSettingsStore::CONFIG_MAX_PER_RUN => 51,
			AutoUpdateSettingsStore::CONFIG_MAX_PER_WEEK => 200,
			default => 0,
		});

		$store = new AutoUpdateSettingsStore($config);

		$this->assertNull($store->getMaxPerRun());
		$this->assertSame(200, $store->getMaxPerWeek());
	}

	public function testSettingACapStoresItAndNullRemovesIt(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueInt')->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_MAX_PER_RUN, 5);
		$config->expects($this->once())->method('deleteKey')->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_MAX_PER_WEEK);

		$store = new AutoUpdateSettingsStore($config);
		$store->setMaxPerRun(5);
		$store->setMaxPerWeek(null);
	}

	public function testTheWaitingListRoundTripsAndSkipsMalformedEntries(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{"app:a":"2026-11-14T02:00:00+00:00","app:b":5,"app:c":""}');
		$config->expects($this->once())->method('setValueString')
			->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_DEFERRED, '{"app:a":"2026-11-14T02:00:00+00:00"}');

		$store = new AutoUpdateSettingsStore($config);
		$deferred = $store->getDeferred();

		$this->assertSame(['app:a' => '2026-11-14T02:00:00+00:00'], $deferred);
		$store->setDeferred($deferred);
	}

	public function testAnEmptyOrBrokenWaitingListReadsEmptyAndClearingRemovesTheKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('not json');
		$config->expects($this->once())->method('deleteKey')->with(Application::APP_ID, AutoUpdateSettingsStore::CONFIG_DEFERRED);

		$store = new AutoUpdateSettingsStore($config);

		$this->assertSame([], $store->getDeferred());
		$store->setDeferred([]);
	}
}
