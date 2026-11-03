<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Which response shapes make the App Store catalogue walk ask for another page.
 *
 * @covers \OCA\Versioniq\Service\Source\AppStoreSource
 */
final class AppStoreNextPageTest extends TestCase {
	use BuildsByParameterName;

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function payloads(): array {
		return [
			'pagination with a next page' => [['pagination' => ['next_page' => 3]], true],
			'pagination says there is no next page' => [['pagination' => ['next_page' => null], 'apps' => [['id' => 'x']]], false],
			'pagination says there is no next page, data on the page' => [['pagination' => ['next_page' => null], 'data' => [['id' => 'x']]], false],
			'pagination without next_page falls back to the apps' => [['pagination' => ['total' => 3], 'apps' => [['id' => 'x']]], true],
			'pagination that is no object falls back to the apps' => [['pagination' => null, 'apps' => [['id' => 'x']]], true],
			'pages says no' => [['pages' => ['next' => false], 'apps' => [['id' => 'x']]], false],
			'apps on the page' => [['apps' => [['id' => 'x']]], true],
			'no apps on the page' => [['apps' => [], 'data' => [['id' => 'x']]], false],
			'data on the page' => [['data' => [['id' => 'x']]], true],
			'no data on the page' => [['data' => []], false],
			'nothing to go by' => [['other' => 1], false],
		];
	}

	/**
	 * @dataProvider payloads
	 * @param array<string, mixed> $payload
	 */
	public function testNextPage(array $payload, bool $expected): void {
		$source = $this->buildByName(AppStoreSource::class);
		$method = new ReflectionMethod(AppStoreSource::class, 'hasPossibleNextPage');

		$this->assertSame($expected, $method->invoke($source, $payload, 1));
	}
}
