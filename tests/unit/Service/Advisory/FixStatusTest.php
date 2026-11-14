<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\FixStatus;
use OCA\Versioniq\Service\Pin\Pin;
use PHPUnit\Framework\TestCase;

/**
 * advisories-per-app-detail D2: why an affected version has no installable fix.
 *
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-an-affected-version-without-an-installable-fix-says-why
 */
final class FixStatusTest extends TestCase {
	private static function affected(?string $fix, string $appId = 'calendar'): array {
		return ['appId' => $appId, 'state' => AdvisoryService::STATE_VULNERABLE, 'recommendedVersion' => $fix];
	}

	public function testAnUnaffectedRowHasNoFixStatus(): void {
		self::assertNull(FixStatus::for(['state' => AdvisoryService::STATE_AVAILABLE, 'recommendedVersion' => null], []));
		self::assertNull(FixStatus::for(['state' => AdvisoryService::STATE_NONE], []));
	}

	public function testInstallableWhenTheSourceListsTheFixAndDoesNotRejectIt(): void {
		$versions = [['version' => '4.7.0', 'serverCompatible' => true], ['version' => '4.8.0', 'serverCompatible' => null]];

		self::assertSame(FixStatus::INSTALLABLE, FixStatus::for(self::affected('4.8.0'), $versions));
	}

	public function testNoFixPublishedWhenNothingResolves(): void {
		self::assertSame(FixStatus::NO_FIX_PUBLISHED, FixStatus::for(self::affected(null), [['version' => '9.9.9', 'serverCompatible' => true]]));
	}

	public function testNotInSourceWhenTheNamedFixIsNotListed(): void {
		$versions = [['version' => '4.7.0', 'serverCompatible' => true], ['version' => '4.9.0', 'serverCompatible' => true]];

		self::assertSame(FixStatus::NOT_IN_SOURCE, FixStatus::for(self::affected('4.8.0'), $versions));
	}

	public function testNeedsANewerServerWhenTheFixAndEveryLaterVersionAreIncompatible(): void {
		$versions = [
			['version' => '4.7.0', 'serverCompatible' => true],
			['version' => '4.8.0', 'serverCompatible' => false],
			['version' => '5.0.0', 'serverCompatible' => false],
		];

		self::assertSame(FixStatus::NEEDS_NEWER_SERVER, FixStatus::for(self::affected('4.8.0'), $versions));
	}

	public function testALaterCompatibleVersionKeepsTheFixInstallable(): void {
		$versions = [['version' => '4.8.0', 'serverCompatible' => false], ['version' => '4.8.1', 'serverCompatible' => true]];

		self::assertSame(FixStatus::INSTALLABLE, FixStatus::for(self::affected('4.8.0'), $versions));
	}

	public function testTheServerRowIsOnlyInstallableOrNoFixPublished(): void {
		self::assertSame(FixStatus::INSTALLABLE, FixStatus::for(self::affected('31.0.9', AdvisoryService::SERVER_KEY), []));
		self::assertSame(FixStatus::NO_FIX_PUBLISHED, FixStatus::for(self::affected(null, AdvisoryService::SERVER_KEY), []));
	}

	public function testAPinBelowAnInstallableFixHoldsTheRow(): void {
		$rows = [
			'calendar' => self::affected('4.8.0') + ['fixStatus' => FixStatus::INSTALLABLE],
			'mail' => self::affected('3.7.25', 'mail') + ['fixStatus' => FixStatus::INSTALLABLE],
			'notes' => self::affected('2.0.0', 'notes') + ['fixStatus' => FixStatus::NOT_IN_SOURCE],
			'deck' => ['appId' => 'deck', 'state' => AdvisoryService::STATE_NONE],
		];
		$pins = [
			'calendar' => new Pin('4.7.0', 'admin', '2026-11-01T00:00:00+00:00'),
			'mail' => new Pin('3.7.25', 'admin', '2026-11-01T00:00:00+00:00'),
			'notes' => new Pin('1.0.0', 'admin', '2026-11-01T00:00:00+00:00'),
		];

		$held = FixStatus::withPins($rows, $pins);

		self::assertTrue($held['calendar']['heldByPin']);
		self::assertFalse($held['mail']['heldByPin'], 'pinned at the fix itself');
		self::assertFalse($held['notes']['heldByPin'], 'no installable fix to be held from');
		self::assertFalse($held['deck']['heldByPin']);
	}
}
