<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\SecurityHoldStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Why the last run held a security fix back (autoupdate-security-first D1).
 *
 * @covers \OCA\Versioniq\Service\AutoUpdate\SecurityHoldStore
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-the-security-level-installs-only-advisory-fixes-in-the-window
 */
final class SecurityHoldStoreTest extends TestCase {
	private string $stored = '';

	private function store(): SecurityHoldStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame(['versioniq', 'auto_update_security_holds'], [$app, $key]);
				$this->stored = $value;
				return true;
			},
		);

		return new SecurityHoldStore($config);
	}

	public function testNothingRecordedReadsEmpty(): void {
		$this->assertSame([], $this->store()->read());
	}

	public function testARunReplacesTheReasons(): void {
		$store = $this->store();
		$store->replace(['deck' => 'needs_major', 'calendar' => 'stale']);

		$this->assertSame('{"calendar":"stale","deck":"needs_major"}', $this->stored);
		$this->assertSame(['calendar' => 'stale', 'deck' => 'needs_major'], $store->read());

		$store->replace([]);
		$this->assertSame('{}', $this->stored);
		$this->assertSame([], $store->read());
	}

	public function testMalformedOrOddValuesAreDropped(): void {
		$store = $this->store();

		$this->stored = '{not json';
		$this->assertSame([], $store->read());

		$this->stored = '"stale"';
		$this->assertSame([], $store->read());

		$this->stored = '{"deck":"no_fix","0":"x","calendar":["stale"]}';
		$this->assertSame(['deck' => 'no_fix'], $store->read());
	}
}
