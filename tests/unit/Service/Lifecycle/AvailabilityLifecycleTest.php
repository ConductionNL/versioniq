<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Lifecycle;

use OCA\Versioniq\Service\Availability\AppVersionCheck;
use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lifecycle\LifecycleSignals;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Source\AppStoreSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The availability sweep records the life cycle signals of each app.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
 */
#[CoversClass(AvailabilityService::class)]
#[UsesClass(AppVersionCheck::class)]
final class AvailabilityLifecycleTest extends TestCase {
	public function testTheSweepCarriesEachAppsSignals(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([
			['id' => 'oldmaps', 'installedVersion' => '1.2.0', 'state' => 'enabled', 'boundSourceId' => 'appstore'],
			['id' => 'calendar', 'installedVersion' => '5.0.0', 'state' => 'enabled', 'boundSourceId' => 'appstore'],
		]);
		$installer->method('isManageableApp')->willReturn(true);
		$calendarVersions = [['version' => '5.1.0', 'serverCompatible' => true, 'releasedAt' => '2026-10-01T00:00:00Z']];
		$installer->method('getAppVersions')->willReturnCallback(static fn (string $id): array => $id === 'oldmaps'
			? ['availableVersions' => [], 'hasError' => true, 'error' => AppStoreSource::NOT_IN_STORE, 'sourceId' => 'appstore']
			: ['availableVersions' => $calendarVersions, 'hasError' => false, 'sourceId' => 'appstore']);

		$asked = [];
		$signals = $this->createMock(LifecycleSignals::class);
		$signals->method('signals')->willReturnCallback(static function (?string $error, array $versions) use (&$asked): array {
			$asked[] = [$error, $versions];

			return ['removedFromStore' => $error !== null, 'noReleaseFor12Months' => null, 'lastReleasedAt' => null];
		});

		$result = (new AvailabilityService($installer, $this->createMock(PinStore::class), new NullLogger(), $signals))->sweep(60.0);

		$this->assertSame([[AppStoreSource::NOT_IN_STORE, []], [null, $calendarVersions]], $asked);
		$this->assertTrue($result['oldmaps']['lifecycle']['removedFromStore']);
		$this->assertFalse($result['calendar']['lifecycle']['removedFromStore']);
		$this->assertSame('5.1.0', $result['calendar']['newestVersion'], 'the existing fields are unchanged');
	}

	public function testWithoutSignalsTheSnapshotKeepsItsShape(): void {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([['id' => 'calendar', 'installedVersion' => '5.0.0', 'state' => 'enabled']]);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->method('getAppVersions')->willReturn(['availableVersions' => [], 'hasError' => false, 'sourceId' => 'appstore']);

		$result = (new AvailabilityService($installer, $this->createMock(PinStore::class), new NullLogger()))->sweep(60.0);

		$this->assertArrayNotHasKey('lifecycle', $result['calendar']);
	}
}
