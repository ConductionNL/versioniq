<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\VersRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `vers:` ranges CSAF documents carry (advisories-ncsc-feed D4).
 *
 * @spec openspec/changes/advisories-ncsc-feed/design.md
 */
class VersRangeTest extends TestCase {

	/**
	 * Ranges, versions and the expected answer.
	 *
	 * @return array<string, array{0: string, 1: string, 2: ?bool}>
	 */
	public static function cases(): array {
		return [
			'below <' => ['vers:generic/<28.0.7', '28.0.6', true],
			'at <' => ['vers:generic/<28.0.7', '28.0.7', false],
			'at <=' => ['vers:generic/<=28.0.7', '28.0.7', true],
			'above >' => ['vers:generic/>27.0.0', '27.0.1', true],
			'at >' => ['vers:generic/>27.0.0', '27.0.0', false],
			'two branches, first' => ['vers:generic/>=27.0.0|<27.1.11|>=28.0.0|<28.0.7', '27.1.10', true],
			'two branches, patched first' => ['vers:generic/>=27.0.0|<27.1.11|>=28.0.0|<28.0.7', '27.1.11', false],
			'two branches, gap' => ['vers:generic/>=27.0.0|<27.1.11|>=28.0.0|<28.0.7', '27.5.0', false],
			'two branches, second' => ['vers:generic/>=27.0.0|<27.1.11|>=28.0.0|<28.0.7', '28.0.0', true],
			'two branches, above' => ['vers:generic/>=27.0.0|<27.1.11|>=28.0.0|<28.0.7', '29.0.0', false],
			'below the first lower bound' => ['vers:generic/>=27.0.0|<27.1.11', '26.0.9', false],
			'open lower then upper' => ['vers:generic/<26.0.0|>=29.0.0', '29.1.0', true],
			'unsorted constraints' => ['vers:generic/<28.0.7|>=28.0.0', '28.0.3', true],
			'star' => ['vers:generic/*', '31.0.0', true],
			'equal' => ['vers:generic/31.0.2', '31.0.2', true],
			'equal other' => ['vers:generic/=31.0.2', '31.0.3', false],
			'not equal excludes' => ['vers:generic/>=30.0.0|!=30.0.5|<31.0.0', '30.0.5', false],
			'not equal keeps the rest' => ['vers:generic/>=30.0.0|!=30.0.5|<31.0.0', '30.0.6', true],
			'only not equal' => ['vers:generic/!=1.0.0', '2.0.0', true],
			'leading v' => ['vers:generic/<v2.0.0', 'v1.9.0', true],
			'unknown' => ['vers:unknown/*', '31.0.0', null],
			'unknown with bounds' => ['vers:unknown/<31.0.0', '30.0.0', null],
			'not vers' => ['<31.0.0', '30.0.0', null],
			'garbage bound' => ['vers:generic/<latest', '30.0.0', null],
			'garbage version' => ['vers:generic/<31.0.0', 'master', null],
			'empty' => ['', '30.0.0', null],
		];
	}

	/**
	 * Each range answers as expected.
	 *
	 * @param string $vers The range.
	 * @param string $version The installed version.
	 * @param bool|null $expected The answer.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatches(string $vers, string $version, ?bool $expected): void {
		$this->assertSame($expected, VersRange::matches($vers, $version));
	}
}
