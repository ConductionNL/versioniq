<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * An IAppConfig mock backed by an array, for the string and int values the
 * proposal stores read and write.
 */
final class InMemoryAppConfig {
	/** @var array<string, string|int> */
	public array $values = [];

	public function mock(TestCase $test, callable $createMock): IAppConfig&MockObject {
		/** @var IAppConfig&MockObject $config */
		$config = $createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->values[$key] ?? $default)
		);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->values[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$key] = $value;

				return true;
			}
		);
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->values[$key] = $value;

				return true;
			}
		);

		return $config;
	}
}
