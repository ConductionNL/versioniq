<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\ReleaseAlert;

use OCA\Versioniq\Service\ReleaseAlert\ReleaseEventDetector;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
 */
final class ReleaseEventDetectorTest extends TestCase {
	private static function entry(?string $newest, ?string $compatible = null, ?string $error = null, ?string $source = 'github:ConductionNL/hermiq'): array {
		return ['installedVersion' => '1.0.0', 'newestVersion' => $newest, 'newestCompatibleVersion' => $compatible ?? $newest, 'sourceId' => $source, 'error' => $error];
	}

	public function testAHigherNewestVersionIsANewRelease(): void {
		$events = (new ReleaseEventDetector(1_790_000_000))->detect(
			['hermiq' => self::entry('1.4.0')],
			['hermiq' => self::entry('1.5.0')],
		);

		self::assertSame([[
			'appId' => 'hermiq',
			'version' => '1.5.0',
			'sourceId' => 'github:ConductionNL/hermiq',
			'compatible' => true,
			'releasedAt' => null,
			'detectedAt' => 1_790_000_000,
		]], $events);
	}

	public function testAnEqualOrLowerNewestVersionIsNot(): void {
		$detector = new ReleaseEventDetector(1);

		self::assertSame([], $detector->detect(['hermiq' => self::entry('1.5.0')], ['hermiq' => self::entry('1.5.0')]));
		self::assertSame([], $detector->detect(['hermiq' => self::entry('1.5.0')], ['hermiq' => self::entry('1.4.9')]));
	}

	public function testAnAppWithAnErrorRaisesNothingNowAndIsNoBaselineLater(): void {
		$detector = new ReleaseEventDetector(1);

		self::assertSame([], $detector->detect(['hermiq' => self::entry('1.4.0')], ['hermiq' => self::entry('1.5.0', error: 'HTTP 403')]));
		self::assertSame([], $detector->detect(['hermiq' => self::entry(null, error: 'HTTP 403')], ['hermiq' => self::entry('1.5.0')]));
	}

	public function testAnAppNewToTheSnapshotAndTheFirstSnapshotSetABaseline(): void {
		$detector = new ReleaseEventDetector(1);

		self::assertSame([], $detector->detect(['deck' => self::entry('1.0.0')], ['hermiq' => self::entry('1.5.0')]));
		self::assertSame([], $detector->detect([], ['hermiq' => self::entry('1.5.0'), 'deck' => self::entry('2.0.0')]));
	}

	public function testANewestVersionTheServerCannotRunIsMarkedIncompatible(): void {
		$events = (new ReleaseEventDetector(7))->detect(
			['calendar' => self::entry('5.1.0', source: 'appstore')],
			['calendar' => self::entry('5.2.0', '5.1.0', source: 'appstore')],
		);

		self::assertCount(1, $events);
		self::assertFalse($events[0]['compatible']);
		self::assertSame('appstore', $events[0]['sourceId']);
		self::assertSame(7, $events[0]['detectedAt']);
	}
}
