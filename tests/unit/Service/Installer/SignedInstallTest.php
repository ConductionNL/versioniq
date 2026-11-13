<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\SignedInstall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignedInstall::class)]
final class SignedInstallTest extends TestCase {
	public function testAFreshInstallHasNoFromVersionAndEndsDisabled(): void {
		$install = new SignedInstall('sampleapp', ['version' => '2.0.0'], '', 'yes');

		self::assertNull($install->fromVersion());
		self::assertSame('no', $install->enabledAfter());
	}

	public function testAnUpgradeKeepsThePreviousVersionAndEnabledState(): void {
		$install = new SignedInstall('sampleapp', ['version' => '2.0.0'], '1.4.0', '["admin"]');

		self::assertSame('1.4.0', $install->fromVersion());
		self::assertSame('["admin"]', $install->enabledAfter());
	}

	public function testRequestedVersionIsTheReleaseVersionString(): void {
		$install = new SignedInstall('sampleapp', ['version' => '2.0.0'], '1.0.0', 'yes');

		self::assertSame('2.0.0', $install->requestedVersion());
	}

	public function testRequestedVersionIsNullWhenMissingOrNotAString(): void {
		self::assertNull((new SignedInstall('sampleapp', [], '1.0.0', 'yes'))->requestedVersion());
		self::assertNull((new SignedInstall('sampleapp', ['version' => 2], '1.0.0', 'yes'))->requestedVersion());
	}
}
