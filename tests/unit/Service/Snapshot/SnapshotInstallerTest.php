<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Snapshot;

use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Snapshot\SnapshotInstaller;
use PHPUnit\Framework\TestCase;

/**
 * The installer as the snapshot classes see it: the installed apps passed
 * through, and one rollback step turned into an install request.
 *
 * @covers \OCA\Versioniq\Service\Snapshot\SnapshotInstaller
 * @uses \OCA\Versioniq\Service\Snapshot\RollbackRunner
 * @uses \OCA\Versioniq\Service\Installer\InstallRequest
 * @uses \OCA\Versioniq\Service\Installer\InstallTarget
 * @uses \OCA\Versioniq\Service\Installer\InstallOptions
 * @uses \OCA\Versioniq\Service\Installer\PinChoice
 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
 */
final class SnapshotInstallerTest extends TestCase {
	public function testInstalledAppsAreTheInstallersCards(): void {
		$cards = [['id' => 'mail', 'installedVersion' => '3.0.0']];
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('getInstalledApps')->willReturn($cards);

		$this->assertSame($cards, (new SnapshotInstaller($installer))->installedApps());
	}

	public function testADowngradeStepIsInstalledFromItsSourceWithDowngradeAllowed(): void {
		$seen = null;
		$installer = $this->createMock(InstallerService::class);
		$installer->expects($this->once())->method('installAppVersion')->willReturnCallback(function (InstallRequest $request) use (&$seen): array {
			$seen = $request;

			return ['statusCode' => 200, 'payload' => ['ok' => true]];
		});

		$result = (new SnapshotInstaller($installer))->install(
			['appId' => 'mail', 'kind' => 'downgrade', 'from' => '3.1.0', 'to' => '3.0.0', 'sourceId' => 'github:c/mail', 'pinnedTo' => null],
			true,
		);

		$this->assertSame(['statusCode' => 200, 'payload' => ['ok' => true]], $result);
		$this->assertInstanceOf(InstallRequest::class, $seen);
		$this->assertSame('mail', $seen->target->appId);
		$this->assertSame('3.0.0', $seen->target->version);
		$this->assertSame('github:c/mail', $seen->target->sourceOverride);
		$this->assertTrue($seen->options->dryRun);
		$this->assertTrue($seen->options->allowDowngrade);
		$this->assertNull($seen->pinChoice->overridePin);
	}

	public function testAPinnedUpgradeStepRepinsAndDoesNotAllowADowngrade(): void {
		$seen = null;
		$installer = $this->createMock(InstallerService::class);
		$installer->method('installAppVersion')->willReturnCallback(function (InstallRequest $request) use (&$seen): array {
			$seen = $request;

			return ['statusCode' => 200, 'payload' => []];
		});

		(new SnapshotInstaller($installer))->install(
			['appId' => 'calendar', 'kind' => 'pinned', 'from' => '4.0.0', 'to' => '4.2.0', 'sourceId' => null, 'pinnedTo' => '4.0.0'],
			false,
		);

		$this->assertInstanceOf(InstallRequest::class, $seen);
		$this->assertFalse($seen->options->dryRun);
		$this->assertFalse($seen->options->allowDowngrade);
		$this->assertSame(InstallerService::OVERRIDE_PIN_REPIN, $seen->pinChoice->overridePin);
	}
}
