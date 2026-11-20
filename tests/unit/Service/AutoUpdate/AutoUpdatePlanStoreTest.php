<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 *
 * @uses \OCA\Versioniq\Service\Settings\StoredSnapshot
 */
final class AutoUpdatePlanStoreTest extends TestCase {
	/** @var array<string, string|int> */
	private array $values = [];

	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->values[$key] ?? $default));
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->values[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->values[$key] = $value;
			return true;
		});
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->values[$key] = $value;
			return true;
		});

		return $config;
	}

	public function testNothingStoredReadsAsNeverPlanned(): void {
		$store = new AutoUpdatePlanStore($this->config(), $this->createMock(LoggerInterface::class));

		$this->assertSame(['plans' => [], 'checkedAt' => null], $store->read());
	}

	public function testASavedPlanReadsBackWithItsTime(): void {
		$store = new AutoUpdatePlanStore($this->config(), $this->createMock(LoggerInterface::class));
		$plans = ['deck' => ['state' => 'planned', 'next' => '1.0.1', 'heldBack' => []]];

		$store->save($plans, 1_790_000_000);

		$this->assertSame(['plans' => $plans, 'checkedAt' => 1_790_000_000], $store->read());
		$this->assertArrayHasKey(AutoUpdatePlanStore::KEY, $this->values);
	}

	public function testAPlanThatCannotBeEncodedKeepsThePreviousSnapshot(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('keeping the previous one'));
		$store = new AutoUpdatePlanStore($this->config(), $logger);
		$store->save(['deck' => ['next' => '1.0.1']], 1_790_000_000);

		$store->save(['deck' => ['next' => "\xB1\x31"]], 1_790_009_999);

		$this->assertSame(['plans' => ['deck' => ['next' => '1.0.1']], 'checkedAt' => 1_790_000_000], $store->read());
	}

	public function testAnUnreadableSnapshotReadsAsNeverPlanned(): void {
		$this->values[AutoUpdatePlanStore::KEY] = '{not json';
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$store = new AutoUpdatePlanStore($this->config(), $logger);

		$this->assertSame(['plans' => [], 'checkedAt' => null], $store->read());
	}
}
