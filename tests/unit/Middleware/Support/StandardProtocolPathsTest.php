<?php

/**
 * Unit tests for the recognition of standard protocol paths.
 *
 * @category Test
 * @package  OCA\Versioniq\Tests\Unit\Middleware\Support
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/versioniq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Middleware\Support;

use OCA\Versioniq\Middleware\Support\StandardProtocolPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * StandardProtocolPaths reduces a path to its app-relative form and matches
 * it exactly against the protocol patterns.
 *
 * @covers \OCA\Versioniq\Middleware\Support\StandardProtocolPaths
 */
class StandardProtocolPathsTest extends TestCase {

	/**
	 * Paths and their app-relative form.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function relativePaths(): array {
		return [
			'app-relative' => ['/api/health', '/api/health'],
			'missing leading slash' => ['api/metrics', '/api/metrics'],
			'several leading slashes' => ['///api/apps', '/api/apps'],
			'index.php request path' => ['/index.php/apps/versioniq/api/health', '/api/health'],
			'ocs request path' => ['/ocs/v2.php/apps/versioniq/api/v1/apps', '/api/v1/apps'],
			'app root without slash' => ['/index.php/apps/versioniq', '/index.php/apps/versioniq'],
			'other app' => ['/index.php/apps/openregister/api/health', '/index.php/apps/openregister/api/health'],
			'prefixed app name' => ['/apps/versioniqx/api/health', '/apps/versioniqx/api/health'],
			'empty path' => ['', '/'],
		];
	}//end relativePaths()

	/**
	 * Each path reduces to the expected app-relative path.
	 *
	 * @param string $path     The path.
	 * @param string $relative The expected app-relative path.
	 *
	 * @return void
	 */
	#[DataProvider('relativePaths')]
	public function testTheAppRelativeFormOfAPath(string $path, string $relative): void {
		$this->assertSame(expected: $relative, actual: StandardProtocolPaths::appRelative(path: $path));
	}//end testTheAppRelativeFormOfAPath()

	/**
	 * The first occurrence of the app root decides where the relative path starts.
	 *
	 * @return void
	 */
	public function testTheFirstAppRootOccurrenceWins(): void {
		$this->assertSame(
			expected: '/api/apps/versioniq/api/health',
			actual: StandardProtocolPaths::appRelative(path: '/apps/versioniq/api/apps/versioniq/api/health')
		);
	}//end testTheFirstAppRootOccurrenceWins()

	/**
	 * Paths and whether they are standard protocol paths.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function protocolPaths(): array {
		return [
			'health' => ['/api/health', true],
			'metrics' => ['/api/metrics', true],
			'health request path' => ['/index.php/apps/versioniq/api/health', true],
			'metrics without leading slash' => ['api/metrics', true],
			'health with trailing slash' => ['/api/health/', false],
			'health sub path' => ['/api/health/live', false],
			'metrics suffix' => ['/api/metricsx', false],
			'versioned health' => ['/api/v1/health', false],
			'upper case' => ['/api/HEALTH', false],
			'own api path' => ['/api/apps', false],
			'health of another app' => ['/index.php/apps/openregister/api/health', false],
			'page' => ['/apps/versioniq/settings', false],
		];
	}//end protocolPaths()

	/**
	 * Only the health check and metrics paths match, exactly.
	 *
	 * @param string $path     The path.
	 * @param bool   $expected Whether it is a standard protocol path.
	 *
	 * @return void
	 */
	#[DataProvider('protocolPaths')]
	public function testWhetherAPathIsAStandardProtocolPath(string $path, bool $expected): void {
		$this->assertSame(expected: $expected, actual: StandardProtocolPaths::matches(path: $path));
	}//end testWhetherAPathIsAStandardProtocolPath()

	/**
	 * The protocols are the health check and the Prometheus metrics, nothing else.
	 *
	 * @return void
	 */
	public function testTheProtocolsListed(): void {
		$this->assertSame(
			expected: ['Health check', 'Prometheus metrics'],
			actual: array_keys(StandardProtocolPaths::PATTERNS)
		);
	}//end testTheProtocolsListed()
}//end class
