<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\ReleaseAlert;

use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseNotifier;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseSweepRecorder;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
 */
final class ReleaseSweepRecorderTest extends TestCase {
	private const HERMIQ_14 = ['hermiq' => ['installedVersion' => '1.4.0', 'newestVersion' => '1.4.0', 'newestCompatibleVersion' => '1.4.0', 'sourceId' => 'github:ConductionNL/hermiq', 'error' => null]];

	private const HERMIQ_15 = ['hermiq' => ['installedVersion' => '1.4.0', 'newestVersion' => '1.5.0', 'newestCompatibleVersion' => '1.5.0', 'sourceId' => 'github:ConductionNL/hermiq', 'error' => null]];

	public function testANewReleaseIsStoredLoggedAndAnnounced(): void {
		$store = $this->createMock(AvailabilityResultStore::class);
		$store->method('read')->willReturn(['updates' => self::HERMIQ_14, 'checkedAt' => 1_789_000_000]);
		$store->expects(self::once())->method('save')->with(self::HERMIQ_15, 1_790_000_000);
		$expected = [['appId' => 'hermiq', 'version' => '1.5.0', 'sourceId' => 'github:ConductionNL/hermiq', 'compatible' => true, 'releasedAt' => null, 'detectedAt' => 1_790_000_000]];
		$notifier = $this->createMock(ReleaseNotifier::class);
		$notifier->expects(self::once())->method('notifyReleases')->with($expected);

		(new ReleaseSweepRecorder($store, $notifier))->record(self::HERMIQ_15, 1_790_000_000);
	}

	public function testTheFirstSweepAnnouncesNothing(): void {
		$store = $this->createMock(AvailabilityResultStore::class);
		$store->method('read')->willReturn(['updates' => [], 'checkedAt' => null]);
		$store->expects(self::once())->method('save');
		$notifier = $this->createMock(ReleaseNotifier::class);
		$notifier->expects(self::never())->method('notifyReleases');

		$recorder = new ReleaseSweepRecorder($store, $notifier);
		$recorder->record(self::HERMIQ_15, 1_790_000_000);

		self::assertSame(['updates' => [], 'checkedAt' => null], $recorder->read());
	}
}
