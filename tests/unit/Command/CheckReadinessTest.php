<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inventory-upgrade-readiness task 2.3: `occ versioniq:readiness` prints the
 * report as JSON or a table and exits 0 whatever the verdicts.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\CheckReadiness;
use OCA\Versioniq\Service\Readiness\UpgradeReadinessService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckReadinessTest extends TestCase {
	private const REPORT = [
		'server' => '32',
		'php' => '8.4',
		'apps' => [
			['id' => 'calendar', 'label' => 'Calendar', 'installedVersion' => '5.0.0', 'verdict' => 'blocked', 'version' => null],
			['id' => 'openregister', 'label' => 'OpenRegister', 'installedVersion' => '2.3.0', 'verdict' => 'updateFirst', 'version' => '2.5.0'],
		],
	];

	public function testJsonOutputAndExitCodeZero(): void {
		$service = $this->createMock(UpgradeReadinessService::class);
		$service->expects(self::once())->method('check')->with('32', '8.4')->willReturn(self::REPORT);
		$tester = new CommandTester(new CheckReadiness($service));

		$code = $tester->execute(['--server' => '32', '--php' => '8.4', '--json' => true]);

		self::assertSame(0, $code);
		self::assertSame(self::REPORT, json_decode($tester->getDisplay(), true));
	}

	public function testTableOutputNamesTheVerdictAndTheVersionToTake(): void {
		$service = $this->createMock(UpgradeReadinessService::class);
		$service->method('check')->willReturn(self::REPORT);
		$tester = new CommandTester(new CheckReadiness($service));

		self::assertSame(0, $tester->execute(['--server' => '32', '--php' => '8.4']));
		$display = $tester->getDisplay();
		self::assertStringContainsString('Target: Nextcloud 32, PHP 8.4', $display);
		self::assertMatchesRegularExpression('/calendar\s+\|\s+5\.0\.0\s+\|\s+Blocked/', $display);
		self::assertMatchesRegularExpression('/openregister\s+\|\s+2\.3\.0\s+\|\s+Update first\s+\|\s+2\.5\.0/', $display);
	}

	public function testThePhpTargetDefaultsToThisServersPhp(): void {
		$service = $this->createMock(UpgradeReadinessService::class);
		$service->expects(self::once())->method('check')->with('33', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION)->willReturn(['server' => '33', 'php' => '', 'apps' => []]);

		self::assertSame(0, (new CommandTester(new CheckReadiness($service)))->execute(['--server' => '33', '--json' => true]));
	}

	public function testBadTargetsExitOneWithoutChecking(): void {
		$service = $this->createMock(UpgradeReadinessService::class);
		$service->expects(self::never())->method('check');
		$tester = new CommandTester(new CheckReadiness($service));

		self::assertSame(1, $tester->execute(['--php' => '8.4']));
		self::assertSame(1, $tester->execute(['--server' => '33', '--php' => 'latest']));
		self::assertStringContainsString('Give --server as a Nextcloud major', $tester->getDisplay());
	}
}
