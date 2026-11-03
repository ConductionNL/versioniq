<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Availability;

use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Golden over AvailabilityService::sweep(): the row recorded per app for a
 * fixed set of installed apps and version listings, compared with
 * fixtures/availability-sweep.golden.json. Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Service\Availability\AvailabilityService
 * @uses \OCA\Versioniq\Service\Pin\Pin
 */
final class AvailabilitySweepGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/availability-sweep.golden.json';

	private static function v(string $version, ?bool $compatible = null): array {
		return ['version' => $version, 'serverCompatible' => $compatible];
	}

	/**
	 * @return array<string, array{apps: list<array<string, mixed>>, listings: array<string, mixed>, pinned?: list<string>, budget?: float}>
	 */
	private static function cases(): array {
		return [
			'lines and patches' => [
				'apps' => [['id' => 'notes', 'installedVersion' => '4.1.2', 'boundSourceId' => 'appstore']],
				'listings' => ['notes' => ['sourceId' => 'appstore', 'availableVersions' => [self::v('4.1.3'), self::v('4.2.0', true), self::v('5.0.1', false), self::v('5.1.0'), self::v(' 6.0.0 '), self::v('7.0.0-beta.1'), self::v('x'), ['version' => 3]]]],
			],
			'older and same versions only' => [
				'apps' => [['id' => 'mail', 'installedVersion' => '3.5.0']],
				'listings' => ['mail' => ['availableVersions' => [self::v('3.4.0'), self::v('3.5.0'), self::v('2.0.0')]]],
			],
			'installed not semver' => [
				'apps' => [['id' => 'deck', 'installedVersion' => '1.9', 'boundSourceId' => 'github:x/deck']],
				'listings' => ['deck' => ['availableVersions' => [self::v('2.0.0'), self::v('1.10.0')]]],
			],
			'only incompatible newer' => [
				'apps' => [['id' => 'talk', 'installedVersion' => '18.0.0']],
				'listings' => ['talk' => ['availableVersions' => [self::v('19.0.0', false), self::v('18.0.1', false)]]],
			],
			'source error' => [
				'apps' => [['id' => 'forms', 'installedVersion' => '4.0.0', 'boundSourceId' => 'appstore']],
				'listings' => ['forms' => ['hasError' => true, 'availableVersions' => [self::v('5.0.0')]]],
			],
			'source error with message and own source' => [
				'apps' => [['id' => 'forms', 'installedVersion' => '4.0.0', 'boundSourceId' => 'appstore']],
				'listings' => ['forms' => ['hasError' => true, 'error' => 'HTTP 403', 'sourceId' => 'github:x/forms']],
			],
			'listing throws' => [
				'apps' => [['id' => 'polls', 'installedVersion' => '7.0.0', 'boundSourceId' => 'codeberg:x/polls']],
				'listings' => ['polls' => 'throw'],
			],
			'skipped apps' => [
				'apps' => [['id' => '', 'installedVersion' => '1.0.0'], ['id' => 'gone', 'installedVersion' => '1.0.0', 'state' => 'notInstalled'], ['id' => 'nov', 'installedVersion' => ''], ['id' => 'nostr', 'installedVersion' => 5], ['id' => 'versioniq', 'installedVersion' => '1.0.0'], ['id' => 'kept', 'installedVersion' => '1.0.0']],
				'listings' => ['kept' => ['availableVersions' => 'not a list']],
			],
			'pinned' => [
				'apps' => [['id' => 'calendar', 'installedVersion' => '5.0.0']],
				'listings' => ['calendar' => ['availableVersions' => [self::v('5.1.0')]]],
				'pinned' => ['calendar'],
			],
			'out of budget' => [
				'apps' => [['id' => 'notes', 'installedVersion' => '4.1.2', 'boundSourceId' => 'appstore'], ['id' => 'mail', 'installedVersion' => '3.5.0']],
				'listings' => [],
				'pinned' => ['mail'],
				'budget' => -1.0,
			],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function caseNames(): array {
		$names = [];
		foreach (array_keys(self::cases()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}

	/**
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		$case = self::cases()[$name];
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn($case['apps']);
		$installer->method('isManageableApp')->willReturnCallback(static fn (string $appId): bool => $appId !== 'versioniq');
		$installer->method('getAppVersions')->willReturnCallback(static function (string $appId) use ($case): array {
			$listing = $case['listings'][$appId] ?? [];
			if ($listing === 'throw') {
				throw new RuntimeException('source unreachable');
			}

			return $listing;
		});
		$pins = $this->createMock(PinStore::class);
		$pins->method('get')->willReturnCallback(static fn (string $appId): ?Pin => in_array($appId, $case['pinned'] ?? [], true) ? new Pin('1.0.0', 'admin', '2026-01-01T00:00:00+00:00', null) : null);

		$service = new AvailabilityService($installer, $pins, new NullLogger());
		$actual = json_encode($service->sweep($case['budget'] ?? 60.0), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}
}
