<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\ExternalInstallOptions;
use OCA\Versioniq\Service\Installer\ExternalInstallRequest;
use OCA\Versioniq\Service\Installer\ExternalInstallRun;
use OCA\Versioniq\Service\Source\SourceBinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExternalInstallRun::class)]
#[UsesClass(ExternalInstallRequest::class)]
#[UsesClass(ExternalInstallOptions::class)]
#[UsesClass(SourceBinding::class)]
final class ExternalInstallRunTest extends TestCase {
	private const SHA = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	/** @param array<string, mixed> $config */
	private function makeRun(string $requested, string $installed, array $config = []): ExternalInstallRun {
		$request = new ExternalInstallRequest('sampleapp', $requested, [], new ExternalInstallOptions(false, false));

		return new ExternalInstallRun($request, new SourceBinding(SourceBinding::KIND_APPSTORE, $config), $installed);
	}

	public function testAFreshInstallHasNoFromVersionIsNoDowngradeAndEndsDisabled(): void {
		$run = $this->makeRun('1.0.0', '');
		$run->previousEnabled = 'yes';

		self::assertNull($run->fromVersion());
		self::assertFalse($run->isDowngrade());
		self::assertSame('no', $run->enabledAfter());
	}

	public function testAnUpgradeKeepsThePreviousEnabledState(): void {
		$run = $this->makeRun('2.0.0', '1.5.0');
		$run->previousEnabled = 'yes';

		self::assertSame('1.5.0', $run->fromVersion());
		self::assertFalse($run->isDowngrade());
		self::assertSame('yes', $run->enabledAfter());
	}

	public function testPreviousEnabledDefaultsToNo(): void {
		self::assertSame('no', $this->makeRun('2.0.0', '1.5.0')->enabledAfter());
	}

	public function testAnOlderRequestedVersionIsADowngrade(): void {
		self::assertTrue($this->makeRun('1.4.9', '1.5.0')->isDowngrade());
	}

	public function testTheSameVersionIsNotADowngrade(): void {
		self::assertFalse($this->makeRun('1.5.0', '1.5.0')->isDowngrade());
	}

	public function testRecordedShaIsLookedUpForTheRequestedVersion(): void {
		$run = $this->makeRun('2.0.0', '1.0.0', ['sha256' => ['2.0.0' => self::SHA, '1.0.0' => str_repeat('f', 64)]]);

		self::assertSame(self::SHA, $run->recordedSha());
	}

	public function testRecordedShaIsNullWhenTheVersionHasNone(): void {
		self::assertNull($this->makeRun('3.0.0', '1.0.0', ['sha256' => ['2.0.0' => self::SHA]])->recordedSha());
	}
}
