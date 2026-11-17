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
use OCA\Versioniq\Service\Lifecycle\LifecycleCard;
use OCA\Versioniq\Service\Lifecycle\LifecycleSignals;
use OCA\Versioniq\Service\Lifecycle\SuccessorList;
use OCA\Versioniq\Service\Pin\PinStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The availability sweep carries the successor each app can be replaced with.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
 */
#[CoversClass(AvailabilityService::class)]
#[UsesClass(AppVersionCheck::class)]
#[UsesClass(LifecycleCard::class)]
final class AvailabilitySuccessorTest extends TestCase {
	private function installer(): InstallerService {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([
			['id' => 'oldname', 'installedVersion' => '1.2.0', 'state' => 'enabled', 'boundSourceId' => 'appstore'],
			['id' => 'calendar', 'installedVersion' => '5.0.0', 'state' => 'enabled', 'boundSourceId' => 'appstore'],
		]);
		$installer->method('isManageableApp')->willReturn(true);
		$installer->method('getAppVersions')->willReturn(['availableVersions' => [], 'hasError' => false, 'sourceId' => 'appstore']);

		return $installer;
	}

	public function testAnAppWithAnOfferedSuccessorCarriesIt(): void {
		$offer = ['from' => 'oldname', 'to' => 'newname', 'source' => 'appstore', 'custom' => true, 'version' => '1.0.0'];
		$successors = $this->createMock(SuccessorList::class);
		$successors->expects($this->exactly(2))->method('offerFor')
			->willReturnCallback(static fn (string $appId): ?array => $appId === 'oldname' ? $offer : null);

		$result = (new AvailabilityService($this->installer(), $this->createMock(PinStore::class), new NullLogger(), lifecycle: new LifecycleCard($this->createMock(LifecycleSignals::class), $successors)))->sweep(60.0);

		$this->assertSame($offer, $result['oldname']['successor']);
		$this->assertArrayNotHasKey('successor', $result['calendar']);
	}

	public function testWithoutTheListTheSnapshotKeepsItsShape(): void {
		$result = (new AvailabilityService($this->installer(), $this->createMock(PinStore::class), new NullLogger()))->sweep(60.0);

		$this->assertArrayNotHasKey('successor', $result['oldname']);
	}
}
