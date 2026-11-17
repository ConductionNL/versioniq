<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Audit;

use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Audit\SeenStateStore
 */
final class SeenStateStoreTest extends TestCase {
	/** @var array<string, string> */
	private array $stored = [];

	/** @var array<string, string> */
	private array $installed = [];

	private function store(?IAppManager $appManager = null): SeenStateStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('searchValues')->willReturnCallback(fn (): array => $this->installed);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;

			return true;
		});

		return new SeenStateStore($config, $appManager ?? $this->createMock(IAppManager::class));
	}

	private function baseline(): SeenStateStore {
		$store = $this->store();
		$store->write(['serverVersion' => '33.0.2', 'apps' => ['forms' => ['version' => '4.3.0', 'enabled' => true]], 'disabledByUpdate' => []]);

		return $store;
	}

	public function testNothingIsSeenBeforeTheBaseline(): void {
		$store = $this->store();

		$this->assertNull($store->read());
		$this->assertFalse($store->hasBaseline());
		$this->assertNull($store->app('forms'));
		$this->assertSame([], $store->disabledByUpdate());
	}

	public function testTheBaselineReadsBackUnderItsKey(): void {
		$store = $this->baseline();

		$this->assertTrue($store->hasBaseline());
		$this->assertSame(['serverVersion' => '33.0.2', 'apps' => ['forms' => ['version' => '4.3.0', 'enabled' => true]], 'disabledByUpdate' => []], $store->read());
		$this->assertSame(['version' => '4.3.0', 'enabled' => true], $store->app('forms'));
		$this->assertSame('{"serverVersion":"33.0.2","apps":{"forms":{"version":"4.3.0","enabled":true}},"disabledByUpdate":{}}', $this->stored['external.seen']);
	}

	public function testAnAppIsNotRememberedBeforeTheBaseline(): void {
		$store = $this->store();

		$store->rememberApp('forms', ['version' => '4.3.0', 'enabled' => true]);
		$store->markDisabledByUpdate('forms', ['toVersion' => '4.4.0', 'compatible' => true, 'compatibleVersion' => null]);

		$this->assertSame([], $this->stored);
	}

	public function testRememberingAnAppKeepsTheOthers(): void {
		$store = $this->baseline();

		$store->rememberApp('calendar', ['version' => '5.2.0', 'enabled' => false]);

		$this->assertSame(['version' => '4.3.0', 'enabled' => true], $store->app('forms'));
		$this->assertSame(['version' => '5.2.0', 'enabled' => false], $store->app('calendar'));
	}

	public function testALeftDisabledMarkIsDroppedOnceTheAppIsEnabledAgain(): void {
		$store = $this->baseline();
		$mark = ['toVersion' => 'Nextcloud 34', 'compatible' => false, 'compatibleVersion' => '4.4.0'];

		$store->markDisabledByUpdate('forms', $mark);
		$store->rememberApp('forms', ['version' => '4.3.0', 'enabled' => false]);
		$this->assertSame(['forms' => $mark], $store->disabledByUpdate());

		$store->rememberApp('forms', ['version' => '4.3.0', 'enabled' => true]);
		$this->assertSame([], $store->disabledByUpdate());
	}

	/**
	 * @dataProvider malformed
	 */
	public function testAMalformedValueReadsAsNoBaseline(string $raw): void {
		$this->stored['external.seen'] = $raw;

		$this->assertNull($this->store()->read());
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformed(): array {
		return [
			'not json' => ['{broken'],
			'a list' => ['[1,2]'],
			'no server version' => ['{"apps":{}}'],
			'apps not an object' => ['{"serverVersion":"33.0.2","apps":"x"}'],
		];
	}

	public function testMalformedEntriesInsideAValidRecordAreLeftOut(): void {
		$this->stored['external.seen'] = '{"serverVersion":"33.0.2","apps":{"forms":{"version":"4.3.0","enabled":true},"bad":{"version":1},"0":{"version":"1","enabled":true}},"disabledByUpdate":{"forms":{"toVersion":"4.4.0","compatible":"yes"},"bad":{}}}';

		$this->assertSame([
			'serverVersion' => '33.0.2',
			'apps' => ['forms' => ['version' => '4.3.0', 'enabled' => true]],
			'disabledByUpdate' => ['forms' => ['toVersion' => '4.4.0', 'compatible' => false, 'compatibleVersion' => null]],
		], $this->store()->read());
	}

	public function testInstalledAppsListsEveryInstalledVersionSortedWithItsEnabledState(): void {
		$this->installed = ['forms' => '4.3.0', 'calendar' => '5.2.0', 'broken' => ''];
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(['forms']);

		$this->assertSame(
			['calendar' => ['version' => '5.2.0', 'enabled' => false], 'forms' => ['version' => '4.3.0', 'enabled' => true]],
			$this->store($appManager)->installedApps(),
		);
	}

	public function testANewVersionKeepsTheEnabledStateLastSeen(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->never())->method('isEnabledForAnyone');
		$store = $this->store($appManager);
		$store->write(['serverVersion' => '33.0.2', 'apps' => ['forms' => ['version' => '4.3.0', 'enabled' => false]], 'disabledByUpdate' => []]);

		$store->rememberVersion('forms', '4.4.0');

		$this->assertSame(['version' => '4.4.0', 'enabled' => false], $store->app('forms'));
	}

	public function testANewVersionOfAnAppNotSeenBeforeAsksTheAppManager(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('isEnabledForAnyone')->with('forms')->willReturn(true);
		$store = $this->store($appManager);
		$store->write(['serverVersion' => '33.0.2', 'apps' => [], 'disabledByUpdate' => []]);

		$store->rememberVersion('forms', '4.4.0');

		$this->assertSame(['version' => '4.4.0', 'enabled' => true], $store->app('forms'));
	}
}
