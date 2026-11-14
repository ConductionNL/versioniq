<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Policy;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PolicyStoreTest extends TestCase {
	private function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}

	public function testGetReturnsNullWhenUnset(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$store = new PolicyStore($config, $this->logger());

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsPolicyForValidJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'level' => 'patch',
			'setBy' => 'alice',
			'setAt' => '2026-07-23T00:00:00+00:00',
		], JSON_THROW_ON_ERROR));

		$store = new PolicyStore($config, $this->logger());
		$policy = $store->get('openregister');

		$this->assertNotNull($policy);
		$this->assertSame('patch', $policy->level);
	}

	public function testGetReturnsNullOnMalformedJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{not valid json');

		$store = new PolicyStore($config, $this->logger());

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsNullOnInvalidPolicyPayload(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode(['level' => 'patch'], JSON_THROW_ON_ERROR));

		$store = new PolicyStore($config, $this->logger());

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsNullWhenStoredLevelIsInvalid(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'level' => 'yolo',
			'setBy' => 'alice',
			'setAt' => '2026-07-23T00:00:00+00:00',
		], JSON_THROW_ON_ERROR));

		$store = new PolicyStore($config, $this->logger());

		$this->assertNull($store->get('openregister'));
	}

	public function testLevelForReturnsNoneWhenUnset(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$store = new PolicyStore($config, $this->logger());

		$this->assertSame(Policy::LEVEL_NONE, $store->levelFor('openregister'));
	}

	public function testLevelForReturnsStoredLevel(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'level' => 'minor',
			'setBy' => 'alice',
			'setAt' => '2026-07-23T00:00:00+00:00',
		], JSON_THROW_ON_ERROR));

		$store = new PolicyStore($config, $this->logger());

		$this->assertSame('minor', $store->levelFor('openregister'));
	}

	public function testAllReturnsPoliciesKeyedByAppIdSkippingMalformedEntries(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getAllValues')->willReturn([
			'policy.openregister' => json_encode(['level' => 'patch', 'setBy' => 'alice', 'setAt' => '2026-07-23T00:00:00+00:00'], JSON_THROW_ON_ERROR),
			'policy.calendar' => 'not valid json',
		]);

		$store = new PolicyStore($config, $this->logger());
		$all = $store->all();

		$this->assertCount(1, $all);
		$this->assertArrayHasKey('openregister', $all);
		$this->assertSame('patch', $all['openregister']->level);
	}

	public function testSetWritesJson(): void {
		$captured = null;
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				'policy.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		$store = new PolicyStore($config, $this->logger());
		$store->set('openregister', new Policy(Policy::LEVEL_PATCH, 'alice', '2026-07-23T00:00:00+00:00'));

		$decoded = json_decode((string)$captured, true);
		$this->assertSame('patch', $decoded['level']);
		$this->assertSame('alice', $decoded['setBy']);
	}

	public function testClearDeletesTheKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('deleteKey')
			->with(Application::APP_ID, 'policy.openregister');

		$store = new PolicyStore($config, $this->logger());
		$store->clear('openregister');
	}

	/**
	 * The effective level: a stored level wins, else the instance default, else none.
	 */
	public function testEffectiveLevelIsTheStoredLevelElseTheDefaultElseNone(): void {
		$stored = json_encode(['level' => 'minor', 'setBy' => 'alice', 'setAt' => '2026-07-23T00:00:00+00:00'], JSON_THROW_ON_ERROR);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'policy.openregister' => $stored,
				AutoUpdateSettingsStore::CONFIG_DEFAULT_LEVEL => 'patch',
				default => $default,
			}
		);
		$settings = new AutoUpdateSettingsStore($config);

		$store = new PolicyStore($config, $this->logger(), $settings);
		$this->assertSame('minor', $store->effectiveLevelFor('openregister'));
		$this->assertSame('policy', $store->levelSourceFor('openregister'));
		$this->assertSame('patch', $store->effectiveLevelFor('calendar'));
		$this->assertSame('default', $store->levelSourceFor('calendar'));
		$this->assertSame('none', $store->levelFor('calendar'), 'levelFor keeps reading only the stored policy');

		$withoutSettings = new PolicyStore($config, $this->logger());
		$this->assertSame('none', $withoutSettings->effectiveLevelFor('calendar'));
	}
}
