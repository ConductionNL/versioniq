<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\BranchAwareRange;
use PHPUnit\Framework\TestCase;

final class BranchAwareRangeTest extends TestCase {
	private BranchAwareRange $range;

	protected function setUp(): void {
		$this->range = new BranchAwareRange();
	}

	/**
	 * The real advisory corpus, captured from `nextcloud/security-advisories`
	 * on 2026-08-21: 161 vulnerability entries across 27 packages.
	 *
	 * @return list<array{ghsa: string, package: string, severity: ?string, range: string, patched: list<string>}>
	 */
	private function corpus(): array {
		$raw = file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-advisories.json');
		self::assertIsString($raw, 'the advisory fixture must be readable');
		/** @var list<array{ghsa: string, package: string, severity: ?string, range: string, patched: list<string>}> $decoded */
		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

		// A fixture that silently emptied would make every sweep below vacuous
		// — the classic "the check passed because it checked nothing".
		self::assertGreaterThan(100, count($decoded), 'the fixture must actually contain the corpus');

		return $decoded;
	}

	// ── The two properties, swept over the whole real corpus ──────────────

	/**
	 * An instance sitting exactly ON a published patch must never be reported
	 * as affected. This is the property that pure-OR evaluation violates:
	 * Talk's `< 21.1.10, < 22.0.11, < 23.0.3` would flag a correct 22.0.11.
	 */
	public function testNoInstanceOnAPatchedVersionIsEverReportedAffected(): void {
		$probes = 0;
		$falsePositives = [];

		foreach ($this->corpus() as $entry) {
			foreach ($entry['patched'] as $patched) {
				$probes++;
				$verdict = $this->range->resolvePatch($patched, $entry['patched']);
				if ($verdict !== null) {
					$falsePositives[] = sprintf(
						'%s [%s] installed=%s patched=%s -> recommended %s',
						$entry['ghsa'],
						$entry['package'],
						$patched,
						implode(',', $entry['patched']),
						$verdict,
					);
				}
			}
		}

		self::assertGreaterThan(400, $probes, 'the sweep must actually probe the corpus');
		self::assertSame([], $falsePositives, "patched versions reported as still vulnerable:\n" . implode("\n", $falsePositives));
	}

	/**
	 * An instance one patch level BELOW a published patch must always be
	 * reported affected. This is the property that AND evaluation violates:
	 * Mail's four lower bounds collapse to `>= 4.3.0`, clearing 3.6.0.
	 */
	public function testEveryVersionJustBelowAPatchIsReportedAffected(): void {
		$probes = 0;
		$misses = [];

		foreach ($this->corpus() as $entry) {
			foreach ($entry['patched'] as $patched) {
				$below = $this->oneBelow($patched);
				if ($below === null) {
					continue;
				}
				// Skip when the constructed version is ITSELF a published
				// patch. With patches 3.0.0/4.0.0/5.0.0, "one below 4.0.0" is
				// 3.0.0 — a fixed version — so the two properties in this
				// class would contradict each other on it. The probe is what
				// is wrong there, not the verdict.
				if ($this->isItselfAPatch($below, $entry['patched'])) {
					continue;
				}
				$probes++;
				if ($this->range->resolvePatch($below, $entry['patched']) === null) {
					$misses[] = sprintf(
						'%s [%s] installed=%s patched=%s -> reported SAFE',
						$entry['ghsa'],
						$entry['package'],
						$below,
						implode(',', $entry['patched']),
					);
				}
			}
		}

		self::assertGreaterThan(350, $probes, 'the sweep must actually probe the corpus');
		self::assertSame([], $misses, "vulnerable versions reported as safe:\n" . implode("\n", $misses));
	}

	// ── The specific shapes those two properties exist to protect ─────────

	/**
	 * Mail, the 66.5% shape. Under the previous AND semantics the four lower
	 * bounds collapsed to `>= 4.3.0` and 3.6.0 was cleared.
	 */
	public function testAffectedOnABranchWhoseOwnPatchIsNotListed(): void {
		$patched = ['3.7.25', '5.5.16', '5.6.20', '5.7.13'];

		self::assertSame('3.7.25', $this->range->resolvePatch('3.6.0', $patched), 'branch 3.6 has no patch, so the nearest patch on major 3 applies');
		self::assertSame('3.7.25', $this->range->resolvePatch('3.5.0', $patched));
	}

	/**
	 * Talk, the multi-upper shape. Under OR semantics 22.0.11 would be flagged
	 * because branch 23 has a later patch.
	 */
	public function testAPatchedBranchIsNotDraggedForwardByALaterBranch(): void {
		$patched = ['21.1.10', '22.0.11', '23.0.3'];

		self::assertNull($this->range->resolvePatch('22.0.11', $patched), 'on its branch patch: fixed');
		self::assertNull($this->range->resolvePatch('22.0.99', $patched), 'above its branch patch: fixed');
		self::assertSame('22.0.11', $this->range->resolvePatch('22.0.10', $patched), 'below its branch patch: affected');
	}

	public function testNewerThanEveryPatchOnItsMajorIsNotAffected(): void {
		self::assertNull($this->range->resolvePatch('5.8.0', ['3.7.25', '5.5.16', '5.6.20', '5.7.13']));
	}

	/**
	 * A branch abandoned without a fix must still be reported affected, even
	 * though the only exit is a major upgrade.
	 *
	 * This test originally asserted the OPPOSITE — that a fix on a higher
	 * major is a migration and should not be recommended. Sweeping the real
	 * corpus disproved it: User OIDC 2.0.0 against patches 3.0.0/4.0.0/5.0.0
	 * has no 2.x fix, so "never cross a major" reports a vulnerable instance
	 * as safe. Forms is the same shape — `>= 4.3.0` fixed only in 5.2.7.
	 */
	public function testABranchAbandonedWithoutAFixIsStillAffected(): void {
		self::assertSame('5.2.7', $this->range->resolvePatch('4.9.0', ['5.2.7']), 'major 4 has no fix; 5.2.7 is the only exit');
		self::assertSame('3.0.0', $this->range->resolvePatch('2.0.0', ['3.0.0', '4.0.0', '5.0.0']));
	}

	/**
	 * The corpus contains records whose `patched_versions` field carries range
	 * OPERATORS rather than bare versions (`>= 28.0.0, >= 29.0.0`). Parsing
	 * must survive that rather than treating it as no patch at all.
	 */
	public function testToleratesOperatorsInsideThePatchedVersionsField(): void {
		$patched = ['>= 28.0.0', '>= 29.0.0', '>= 30.0.0'];

		self::assertSame('>= 28.0.0', $this->range->resolvePatch('27.0.0', $patched), 'below every patch: affected');
		self::assertNull($this->range->resolvePatch('31.0.0', $patched), 'above every patch on no listed branch: fixed');
	}

	public function testSingleUpperBoundShape(): void {
		self::assertSame('2.7.2', $this->range->resolvePatch('2.7.1', ['2.7.2']));
		self::assertNull($this->range->resolvePatch('2.7.2', ['2.7.2']));
	}

	public function testFourSegmentServerVersionsCompareNumerically(): void {
		// Server advisories carry four-segment patches such as 29.0.16.10.
		self::assertSame('29.0.16.10', $this->range->resolvePatch('29.0.16.9', ['29.0.16.10']));
		self::assertNull($this->range->resolvePatch('29.0.16.10', ['29.0.16.10']));
		// version_compare() would order 29.0.16 BELOW 29.0.16.0; segment
		// comparison treats them as equal, which is what the data means.
		self::assertNull($this->range->resolvePatch('29.0.16', ['29.0.16']));
	}

	public function testNoPatchedVersionsMeansNoVerdict(): void {
		self::assertNull($this->range->resolvePatch('1.0.0', []));
		self::assertNull($this->range->resolvePatch('1.0.0', ['   ']));
	}

	/**
	 * Numeric equality against any published patch, ignoring operators and
	 * segment padding, so `3.0.0` matches `>= 3.0.0` and `3.0.0.0`.
	 *
	 * @param list<string> $patched
	 */
	private function isItselfAPatch(string $version, array $patched): bool {
		$normalise = static function (string $v): array {
			preg_match_all('/\d+/', $v, $m);

			return array_pad(array_map('intval', array_slice($m[0], 0, 4)), 4, 0);
		};
		$target = $normalise($version);
		foreach ($patched as $candidate) {
			if ($normalise($candidate) === $target) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return string|null one patch level below, or null when there is no
	 *                     lower neighbour to construct
	 */
	private function oneBelow(string $version): ?string {
		preg_match_all('/\d+/', $version, $matches);
		$parts = array_map('intval', $matches[0]);
		for ($i = count($parts) - 1; $i >= 0; $i--) {
			if ($parts[$i] > 0) {
				$parts[$i]--;

				return implode('.', $parts);
			}
		}

		return null;
	}
}
