<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * inventory-upgrade-readiness task 1.1: App Store versions carry the
 * release's PHP range next to its server range, and say whether they run on
 * the PHP this server runs.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/version-management/spec.md#requirement-versions-say-whether-they-run-on-this-servers-php
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AppStorePhpRangeTest extends TestCase {
	use BuildsByParameterName;

	/**
	 * Normalise releases on a Nextcloud 32 server.
	 *
	 * @param list<mixed> $releases The App Store releases.
	 * @return list<array<string, mixed>>
	 */
	private function normalize(array $releases): array {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('32.0.1.2');
		$source = $this->buildByName(AppStoreSource::class, ['config' => $config]);

		return (new ReflectionMethod(AppStoreSource::class, 'normalizeVersions'))->invoke($source, $releases);
	}

	public function testAMatchingAFailingAndAnAbsentPhpRange(): void {
		$running = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$next = PHP_MAJOR_VERSION . '.' . (PHP_MINOR_VERSION + 1);
		$versions = $this->normalize([
			['version' => '3.0.0', 'platformVersionSpec' => '>=32.0.0', 'phpVersionSpec' => '>=' . $next . '.0'],
			['version' => '2.0.0', 'platformVersionSpec' => '>=31.0.0 <=32', 'phpVersionSpec' => '>=' . $running . '.0 <' . $next . '.0'],
			['version' => '1.0.0', 'platformVersionSpec' => '>=30.0.0', 'phpVersionSpec' => ''],
		]);

		$this->assertSame(
			[
				['3.0.0', '>=32.0.0', '>=' . $next . '.0', false],
				['2.0.0', '>=31.0.0 <=32', '>=' . $running . '.0 <' . $next . '.0', true],
				['1.0.0', '>=30.0.0', null, null],
			],
			array_map(static fn (array $v): array => [$v['version'], $v['platformSpec'], $v['phpSpec'], $v['phpCompatible']], $versions)
		);
		$this->assertSame([true, true, true], array_column($versions, 'serverCompatible'));
	}

	public function testThePhpRangeUsesTheServerRangeBoundRules(): void {
		$this->assertTrue(AppStoreSource::satisfiesPhpSpec('8.3.6', '>=8.1 <=8.3'));
		$this->assertFalse(AppStoreSource::satisfiesPhpSpec('8.4.0', '>=8.1.0 <8.4.0'));
		$this->assertTrue(AppStoreSource::satisfiesPhpSpec('8.4.1', '*'));
		$this->assertNull(AppStoreSource::satisfiesPhpSpec('8.4.1', 'php8'));
	}
}
