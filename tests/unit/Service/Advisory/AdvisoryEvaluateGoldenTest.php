<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\BranchAwareRange;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use PHPUnit\Framework\TestCase;

/**
 * Golden over AdvisoryService::evaluate(): state, summarised advisories and
 * recommendation per case, compared with fixtures/advisory-evaluate.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\Advisory\AdvisoryService
 * @uses \OCA\Versioniq\Service\Advisory\BranchAwareRange
 * @uses \OCA\Versioniq\Service\Advisory\AdvisoryMatching
 * @uses \OCA\Versioniq\Service\Source\SourceLookup
 */
final class AdvisoryEvaluateGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/advisory-evaluate.golden.json';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function advisory(string $id, array $affected, ?string $patched = null, ?array $branchPatches = null): array {
		$advisory = ['id' => $id, 'severity' => 'high', 'summary' => 'Issue ' . $id, 'affected' => $affected, 'firstPatchedVersion' => $patched];
		if ($branchPatches !== null) {
			$advisory['patchedVersions'] = $branchPatches;
		}

		return $advisory;
	}

	/**
	 * @return array<string, array{0: string, 1: list<array<string, mixed>>, 2: list<string>}>
	 */
	private static function cases(): array {
		$available = ['1.0.0', '1.0.5', '1.1.0', '1.2.3', '2.0.0', '2.1.0'];

		return [
			'no advisories' => ['1.0.0', [], $available],
			'clause, not affected' => ['1.2.3', [self::advisory('A', ['< 1.2.0'], '1.2.0')], $available],
			'clause, affected, nearest resolving' => ['1.0.0', [self::advisory('A', ['< 1.2.0'], '1.2.0')], $available],
			'clause, affected, nothing resolves' => ['2.1.0', [self::advisory('A', ['>= 2.0.0'], null)], $available],
			'clause, empty range affects all' => ['1.0.0', [self::advisory('A', [], null)], $available],
			'two clauses, one affects' => ['1.1.0', [self::advisory('A', ['< 1.0.0'], '1.0.0'), self::advisory('B', ['< 1.2.0'], '1.2.0')], $available],
			'branch patch affects' => ['1.0.0', [self::advisory('F', ['>= 1.0.0, < 1.0.5'], null, ['1.0.5', '2.0.1'])], $available],
			'branch patch already applied' => ['1.0.5', [self::advisory('F', ['>= 1.0.0'], null, ['1.0.5', '2.0.1'])], $available],
			'two branch patches, lowest wins' => ['1.0.0', [self::advisory('F', [], null, ['1.2.3']), self::advisory('G', [], null, ['1.0.5'])], $available],
			'branch beats clause recommendation' => ['1.0.0', [self::advisory('A', ['< 2.0.0'], '2.0.0'), self::advisory('F', [], null, ['1.0.5'])], $available],
			'empty patch list uses clauses' => ['1.0.0', [self::advisory('A', ['< 1.1.0'], '1.1.0', [])], $available],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function caseNames(): array {
		$names = [];
		foreach (array_keys(self::cases()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}

	/**
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		[$installed, $advisories, $available] = self::cases()[$name];
		$service = $this->buildByName(AdvisoryService::class, ['branchRange' => new BranchAwareRange()]);
		$actual = json_encode($service->evaluate($installed, $advisories, $available), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}
}
