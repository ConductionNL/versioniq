<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inventory-upgrade-readiness task 2.1: one case per verdict, the ranges an
 * installed app's info.xml declares, and the range rules.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Readiness;

use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Readiness\UpgradeReadinessService;
use OCA\Versioniq\Service\Readiness\VersionRanges;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

final class UpgradeReadinessServiceTest extends TestCase {
	/**
	 * An info.xml dependency block.
	 *
	 * @return array<string, mixed>
	 */
	private static function info(string $ncMin, string $ncMax, string $phpMin = '', string $phpMax = ''): array {
		$deps = ['nextcloud' => ['@attributes' => array_filter(['min-version' => $ncMin, 'max-version' => $ncMax])]];
		if ($phpMin !== '' || $phpMax !== '') {
			$deps['php'] = ['@attributes' => array_filter(['min-version' => $phpMin, 'max-version' => $phpMax])];
		}

		return ['dependencies' => $deps];
	}

	/**
	 * A service over installed apps, their info.xml and their version listings.
	 *
	 * @param list<array<string, mixed>> $apps
	 * @param array<string, array<string, mixed>|null> $infos
	 * @param array<string, array<string, mixed>> $listings
	 */
	private function service(array $apps, array $infos, array $listings): UpgradeReadinessService {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn($apps);
		$installer->method('getAppVersions')->willReturnCallback(
			static fn (string $appId): array => $listings[$appId] ?? ['versions' => [], 'statusCode' => 200]
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppInfo')->willReturnCallback(static fn (string $appId): ?array => $infos[$appId] ?? null);

		return new UpgradeReadinessService($installer, $appManager);
	}

	private static function app(string $id, ?string $version, bool $shipped = false): array {
		return ['id' => $id, 'label' => ucfirst($id), 'installedVersion' => $version, 'isShipped' => $shipped];
	}

	public function testOneVerdictPerApp(): void {
		$report = $this->service(
			[
				self::app('files', '2.0.0', true),
				self::app('calendar', '5.0.0'),
				self::app('openregister', '2.3.0'),
				self::app('oldapp', '1.0.0'),
				self::app('forgeapp', '0.4.0'),
				self::app('notinstalled', null),
			],
			[
				'calendar' => self::info('31', '34', '8.1', '8.4'),
				'openregister' => self::info('30', '32'),
				'oldapp' => self::info('28', '31'),
				'forgeapp' => self::info('30', '32'),
			],
			[
				'openregister' => ['statusCode' => 200, 'versions' => [
					['version' => '2.6.0', 'platformSpec' => '>=33.0.0 <=34', 'phpSpec' => '>=8.1'],
					['version' => '2.2.0', 'platformSpec' => '>=33.0.0'],
					['version' => '2.5.0', 'platformSpec' => '>=31.0.0 <=33', 'phpSpec' => '>=8.1.0 <8.5.0'],
					['version' => '2.4.0', 'platformSpec' => '>=30.0.0 <=32'],
				]],
				'oldapp' => ['statusCode' => 200, 'versions' => [
					['version' => '1.1.0', 'platformSpec' => '>=29.0.0 <=32'],
				]],
				'forgeapp' => ['statusCode' => 200, 'versions' => [
					['version' => '0.5.0', 'serverCompatible' => null],
				]],
			],
		)->check('33', '8.2');

		self::assertSame('33', $report['server']);
		self::assertSame('8.2', $report['php']);
		self::assertSame(
			[
				['files', 'shipped', null],
				['calendar', 'ready', null],
				['openregister', 'updateFirst', '2.5.0'],
				['oldapp', 'blocked', null],
				['forgeapp', 'unknown', null],
			],
			array_map(static fn (array $row): array => [$row['id'], $row['verdict'], $row['version']], $report['apps'])
		);
		self::assertSame(['id' => 'openregister', 'label' => 'Openregister', 'installedVersion' => '2.3.0', 'verdict' => 'updateFirst', 'version' => '2.5.0'], $report['apps'][2]);
	}

	public function testAPhpUpgradeIsCheckedTheSameWay(): void {
		$service = $this->service(
			[self::app('calendar', '5.0.0')],
			['calendar' => self::info('31', '34', '8.1', '8.3')],
			['calendar' => ['statusCode' => 200, 'versions' => []]],
		);

		self::assertSame('blocked', $service->check('32', '8.4')['apps'][0]['verdict']);
		self::assertSame('ready', $service->check('32', '8.3')['apps'][0]['verdict']);
	}

	public function testAnAnsweringSourceIsNeededToCallAnAppBlocked(): void {
		$service = $this->service(
			[self::app('oldapp', '1.0.0'), self::app('noinfo', '1.0.0')],
			['oldapp' => self::info('28', '31')],
			[
				'oldapp' => ['statusCode' => 502, 'versions' => []],
				'noinfo' => ['statusCode' => 200, 'versions' => [['version' => '0.9.0', 'platformSpec' => '>=33.0.0']]],
			],
		);

		self::assertSame(['unknown', 'unknown'], array_column($service->check('33', '8.2')['apps'], 'verdict'));
	}

	public function testTheRangesAnInstalledAppDeclares(): void {
		self::assertSame(
			['platformSpec' => '>=30 <=33', 'phpSpec' => '>=8.1'],
			VersionRanges::fromAppInfo(self::info('30', '33', '8.1'))
		);
		self::assertSame(['platformSpec' => '', 'phpSpec' => ''], VersionRanges::fromAppInfo([]));
		self::assertSame(['platformSpec' => '>=30', 'phpSpec' => ''], VersionRanges::fromAppInfo(['dependencies' => ['nextcloud' => ['@attributes' => ['min-version' => ' 30 ', 'max-version' => '']]]]));
	}

	public function testTheRangeRules(): void {
		self::assertTrue(VersionRanges::admits('>=30 <=33', '', '33', '8.4'));
		self::assertFalse(VersionRanges::admits('>=30 <=32', '>=8.1', '33', '8.2'));
		self::assertFalse(VersionRanges::admits('>=30 <=33', '>=8.1 <=8.3', '33', '8.4'));
		self::assertNull(VersionRanges::admits('', '>=8.1', '33', '8.2'));
		self::assertNull(VersionRanges::admits('>=30', 'php8', '33', '8.2'));
	}
}
