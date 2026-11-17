<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Channel;

use InvalidArgumentException;
use OCA\Versioniq\Service\Channel\ChannelStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-long-term-support-channel-offers-only-marked-release-lines
 */
final class ChannelStoreTest extends TestCase {
	/** @var array<string, string> */
	private array $values = [];

	private function store(): ChannelStore {
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
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->values[$key]);
			}
		);

		return new ChannelStore($config);
	}

	public function testAbsentMeansServerAndAnAppFollowsTheDefault(): void {
		$store = $this->store();

		$this->assertSame('server', $store->defaultChannel());
		$this->assertNull($store->appChannel('hermiq'));
		$this->assertSame('server', $store->effectiveChannel('hermiq'));

		$store->setDefault('stable');
		$this->assertSame('stable', $store->effectiveChannel('hermiq'));
	}

	public function testAnAppsOwnChannelWinsUntilCleared(): void {
		$store = $this->store();
		$store->setDefault('stable');
		$store->setAppChannel('hermiq', 'beta');

		$this->assertSame('beta', $store->effectiveChannel('hermiq'));
		$this->assertSame('stable', $store->effectiveChannel('openregister'));
		$this->assertSame('beta', $this->values['channel.hermiq']);

		$store->clearAppChannel('hermiq');
		$this->assertSame('stable', $store->effectiveChannel('hermiq'));
	}

	public function testAnUnknownChannelIsRefusedAndNothingChanges(): void {
		$store = $this->store();
		try {
			$store->setAppChannel('hermiq', 'betta');
			$this->fail('an unknown channel must be refused');
		} catch (InvalidArgumentException) {
		}
		$this->expectException(InvalidArgumentException::class);
		try {
			$store->setDefault('nightly');
		} finally {
			$this->assertSame([], $this->values);
		}
	}

	public function testAMalformedStoredValueReadsAsAbsent(): void {
		$this->values = ['channel.default' => 'nightly', 'channel.hermiq' => '', 'lts_lines.hermiq' => '{not json'];
		$store = $this->store();

		$this->assertSame('server', $store->defaultChannel());
		$this->assertNull($store->appChannel('hermiq'));
		$this->assertSame([], $store->ltsLines('hermiq'));

		$this->values['lts_lines.hermiq'] = '"2.3"';
		$this->assertSame([], $store->ltsLines('hermiq'));
		$this->values['lts_lines.hermiq'] = '["2.3", 4, "2.3.1", "2", "2.3"]';
		$this->assertSame(['2.3', '2'], $store->ltsLines('hermiq'));
	}

	public function testLinesAreStoredDedupedAndClearedByAnEmptyList(): void {
		$store = $this->store();
		$store->setLtsLines('openregister', [' 2.3', '2.3', '3']);

		$this->assertSame('["2.3","3"]', $this->values['lts_lines.openregister']);
		$this->assertSame(['2.3', '3'], $store->ltsLines('openregister'));

		$store->setLtsLines('openregister', []);
		$this->assertArrayNotHasKey('lts_lines.openregister', $this->values);
	}

	public function testAnInvalidLineIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->store()->setLtsLines('openregister', ['2.3.1']);
	}
}
