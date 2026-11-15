<?php

/**
 * Unit tests for the deprecation of unversioned API paths and their `/api/v1/` successors.
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

use OCA\Versioniq\Middleware\Support\ApiPathAliases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ApiPathAliases deprecates unversioned `/api/` paths, except versioned and
 * standard protocol paths, and maps each to its `/api/v1/` successor.
 *
 * @covers \OCA\Versioniq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Versioniq\Middleware\Support\StandardProtocolPaths
 */
class ApiPathAliasesTest extends TestCase {

	/**
	 * Paths and whether they are deprecated.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function deprecations(): array {
		return [
			'unversioned api path' => ['/api/apps', true],
			'api root slash' => ['/api/', true],
			'unversioned without leading slash' => ['api/apps/a/pin', true],
			'unversioned request path' => ['/index.php/apps/versioniq/api/apps', true],
			'versioned' => ['/api/v1/apps', false],
			'versioned root' => ['/api/v2', false],
			'upper case version' => ['/api/V1/apps', false],
			'nested version' => ['/api/zgw/zaken/v1/zaken', false],
			'version longer than three digits' => ['/api/v1234/apps', true],
			'version-like segment name' => ['/api/v1x/apps', true],
			'health' => ['/api/health', false],
			'metrics request path' => ['/ocs/v2.php/apps/versioniq/api/metrics', false],
			'bare api' => ['/api', false],
			'page' => ['/apps/versioniq/settings', false],
			'other app api' => ['/index.php/apps/openregister/api/objects', false],
		];
	}//end deprecations()

	/**
	 * Each path is deprecated or not as expected.
	 *
	 * @param string $path     The path.
	 * @param bool   $expected Whether it is deprecated.
	 *
	 * @return void
	 */
	#[DataProvider('deprecations')]
	public function testWhetherAPathIsDeprecated(string $path, bool $expected): void {
		$this->assertSame(expected: $expected, actual: ApiPathAliases::isDeprecated(path: $path));
	}//end testWhetherAPathIsDeprecated()

	/**
	 * Paths and their successor, null when not deprecated.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function successors(): array {
		return [
			'api path' => ['/api/apps/a/releases', '/api/v1/apps/a/releases'],
			'index.php request path' => ['/index.php/apps/versioniq/api/apps', '/index.php/apps/versioniq/api/v1/apps'],
			'api root slash' => ['/api/', '/api/v1/'],
			'versioned' => ['/api/v1/apps', null],
			'nested version' => ['/api/zgw/zaken/v1/zaken', null],
			'metrics' => ['/api/metrics', null],
			'other app api' => ['/index.php/apps/openregister/api/objects', null],
		];
	}//end successors()

	/**
	 * Each path has the expected successor, prefix kept.
	 *
	 * @param string      $path      The path.
	 * @param string|null $successor The expected successor.
	 *
	 * @return void
	 */
	#[DataProvider('successors')]
	public function testTheSuccessorOfAPath(string $path, ?string $successor): void {
		$this->assertSame(expected: $successor, actual: ApiPathAliases::successorOf(path: $path));
	}//end testTheSuccessorOfAPath()

	/**
	 * Every successor is itself a versioned path that is not deprecated.
	 *
	 * @return void
	 */
	public function testASuccessorIsNotDeprecatedItself(): void {
		$successor = ApiPathAliases::successorOf(path: '/index.php/apps/versioniq/api/apps/a/pin');

		$this->assertSame(expected: '/index.php/apps/versioniq/api/v1/apps/a/pin', actual: $successor);
		$this->assertFalse(ApiPathAliases::isDeprecated(path: (string)$successor));
		$this->assertNull(ApiPathAliases::successorOf(path: (string)$successor));
	}//end testASuccessorIsNotDeprecatedItself()

	/**
	 * The version prefix and the RFC 9745 deprecation date (2026-11-12 00:00 UTC).
	 *
	 * @return void
	 */
	public function testTheVersionPrefixAndDeprecationDate(): void {
		$this->assertSame(expected: '/api/v1', actual: ApiPathAliases::VERSION_PREFIX);
		$this->assertSame(expected: '@' . gmmktime(0, 0, 0, 11, 12, 2026), actual: ApiPathAliases::DEPRECATED_SINCE);
	}//end testTheVersionPrefixAndDeprecationDate()
}//end class
