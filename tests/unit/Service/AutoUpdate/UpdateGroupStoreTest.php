<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\AutoUpdate\UpdateGroup;
use OCA\Versioniq\Service\AutoUpdate\UpdateGroupStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Update groups are stored as one JSON list (autoupdate-run-batching D2).
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class UpdateGroupStoreTest extends TestCase {
	private static function group(): UpdateGroup {
		return new UpdateGroup('g1', 'Core stack', ['openregister', 'opencatalogi', 'decidiq'], 'admin', '2026-11-14T02:00:00+00:00');
	}

	public function testAGroupRoundTripsInOrder(): void {
		$stored = '';
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueString')
			->with(Application::APP_ID, 'auto_update.groups', $this->isType('string'))
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored = $value;
				return true;
			});
		$config->method('getValueString')->willReturnCallback(static function () use (&$stored): string {
			return $stored;
		});

		$store = new UpdateGroupStore($config, $this->createMock(LoggerInterface::class));
		$store->replaceAll([self::group()]);
		$groups = $store->all();

		$this->assertCount(1, $groups);
		$this->assertSame(self::group()->toArray(), $groups[0]->toArray());
		$this->assertSame(['openregister', 'opencatalogi', 'decidiq'], $groups[0]->apps);
	}

	public function testUnsetReadsAsNoGroups(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertSame([], (new UpdateGroupStore($config, $logger))->all());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformedValues(): array {
		return [
			'not json' => ['{not json'],
			'not a list' => ['{"id":"g1"}'],
		];
	}

	/**
	 * @dataProvider malformedValues
	 */
	public function testMalformedJsonReadsAsNoGroupsAndIsLogged(string $raw): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($raw);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertSame([], (new UpdateGroupStore($config, $logger))->all());
	}

	public function testAnInvalidEntryIsSkippedAndTheRestKept(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			['id' => 'g0', 'name' => 'Alone', 'apps' => ['openregister'], 'setBy' => 'admin', 'setAt' => '2026-11-14T02:00:00+00:00'],
			'not an object',
			self::group()->toArray(),
		], JSON_THROW_ON_ERROR));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');

		$groups = (new UpdateGroupStore($config, $logger))->all();

		$this->assertCount(1, $groups);
		$this->assertSame('g1', $groups[0]->id);
	}

	/**
	 * @return array<string, array{list<string>}>
	 */
	public static function invalidAppLists(): array {
		return [
			'one app' => [['openregister']],
			'duplicate app' => [['openregister', 'openregister']],
			'empty app id' => [['openregister', '']],
			'twenty-one apps' => [array_map(static fn (int $i): string => 'app' . $i, range(1, 21))],
		];
	}

	/**
	 * @dataProvider invalidAppLists
	 *
	 * @param list<string> $apps
	 */
	public function testAGroupRefusesAnInvalidAppList(array $apps): void {
		$this->expectException(InvalidArgumentException::class);

		new UpdateGroup('g1', 'Core stack', $apps, 'admin', '2026-11-14T02:00:00+00:00');
	}

	public function testAGroupOfTwentyAppsIsAccepted(): void {
		$apps = array_map(static fn (int $i): string => 'app' . $i, range(1, 20));

		$this->assertSame($apps, (new UpdateGroup('g1', 'Big', $apps, 'admin', '2026-11-14T02:00:00+00:00'))->apps);
	}
}
