<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Lifecycle;

use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * An IAppConfig double that keeps what is written, for the life cycle tests.
 */
final class InMemoryAppConfig {
	/** @var array<string, string|int> */
	public array $values = [];

	public static function for(TestCase $test, array $values = []): array {
		$store = new self();
		$store->values = $values;
		$mock = (new \ReflectionMethod(TestCase::class, 'createMock'))->invoke($test, IAppConfig::class);
		$mock->method('hasKey')->willReturnCallback(static fn (string $app, string $key): bool => array_key_exists($key, $store->values));
		$mock->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => (string)($store->values[$key] ?? $default));
		$mock->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => (int)($store->values[$key] ?? $default));
		$mock->method('setValueString')->willReturnCallback(static function (string $app, string $key, string $value) use ($store): bool {
			$store->values[$key] = $value;

			return true;
		});

		return [$mock, $store];
	}
}
