<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryClauses;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for AdvisoryClauses::from(): the affected-version clauses of an
 * advisory arrive as a comma-separated string or a list, and come out as a
 * list of trimmed, non-empty strings.
 *
 * @covers \OCA\Versioniq\Service\Advisory\AdvisoryClauses
 */
final class AdvisoryClausesTest extends TestCase {
	/**
	 * @return array<string, array{0: mixed, 1: list<string>}>
	 */
	public static function shapes(): array {
		return [
			'comma separated string' => ['>=1.0.0 <1.2.3, >=2.0.0 <2.0.5', ['>=1.0.0 <1.2.3', '>=2.0.0 <2.0.5']],
			'single clause string' => [' <3.0.0 ', ['<3.0.0']],
			'empty string' => ['', []],
			'string of separators' => [' , ,', []],
			'list with blanks and non-strings' => [['<1.0', '  ', 7, null, ['<2.0'], ' >=3.0 '], ['<1.0', '>=3.0']],
			'map keeps only values' => [['a' => '<1.0', 'b' => '<2.0'], ['<1.0', '<2.0']],
			'null' => [null, []],
			'number' => [12, []],
			'boolean' => [true, []],
		];
	}

	/**
	 * @dataProvider shapes
	 * @param list<string> $expected
	 */
	public function testEveryShapeBecomesAListOfTrimmedClauses(mixed $raw, array $expected): void {
		$this->assertSame($expected, AdvisoryClauses::from($raw));
	}
}
