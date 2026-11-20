<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\Policy\Policy;
use PHPUnit\Framework\TestCase;

final class CandidateSelectorTest extends TestCase {
	public function testNoneLevelNeverSelectsACandidate(): void {
		$selector = new CandidateSelector();

		$this->assertNull($selector->select('2.3.0', ['2.3.4', '2.4.0'], Policy::LEVEL_NONE));
	}

	public function testPatchLevelSelectsTheHighestSameMinorPatch(): void {
		$selector = new CandidateSelector();

		$this->assertSame('2.3.4', $selector->select('2.3.0', ['2.3.4', '2.4.0'], Policy::LEVEL_PATCH));
	}

	public function testPatchLevelExcludesADifferentMinor(): void {
		$selector = new CandidateSelector();

		$this->assertNull($selector->select('2.3.0', ['2.4.0'], Policy::LEVEL_PATCH));
	}

	public function testMinorLevelSelectsTheHighestSameMajor(): void {
		$selector = new CandidateSelector();

		$this->assertSame('2.4.0', $selector->select('2.3.0', ['2.3.4', '2.4.0'], Policy::LEVEL_MINOR));
	}

	public function testMinorLevelExcludesADifferentMajor(): void {
		$selector = new CandidateSelector();

		$this->assertNull($selector->select('2.3.0', ['3.0.0'], Policy::LEVEL_MINOR));
	}

	public function testAllLevelSelectsTheHighestAvailable(): void {
		$selector = new CandidateSelector();

		$this->assertSame('3.0.0', $selector->select('2.3.0', ['2.3.4', '2.4.0', '3.0.0'], Policy::LEVEL_ALL));
	}

	public function testNeverSelectsAVersionThatIsNotStrictlyNewer(): void {
		$selector = new CandidateSelector();

		$this->assertNull($selector->select('2.3.0', ['2.3.0', '2.2.9'], Policy::LEVEL_ALL));
	}

	public function testPreReleaseSuffixedCandidateIsExcludedFromPatch(): void {
		$selector = new CandidateSelector();

		$this->assertNull($selector->select('2.3.0', ['2.3.4-beta1'], Policy::LEVEL_PATCH));
	}

	public function testPreReleaseSuffixedCandidateIsExcludedFromMinor(): void {
		$selector = new CandidateSelector();

		$this->assertNull($selector->select('2.3.0', ['2.4.0-rc1'], Policy::LEVEL_MINOR));
	}

	public function testPreReleaseSuffixedCandidateQualifiesForAll(): void {
		$selector = new CandidateSelector();

		$this->assertSame('2.4.0-rc1', $selector->select('2.3.0', ['2.4.0-rc1'], Policy::LEVEL_ALL));
	}

	public function testNonSemverInstalledVersionExcludesPatchAndMinor(): void {
		$selector = new CandidateSelector();

		$this->assertNull($selector->select('2.3', ['2.3.4'], Policy::LEVEL_PATCH));
		$this->assertNull($selector->select('2.3', ['2.4.0'], Policy::LEVEL_MINOR));
	}

	public function testNonSemverInstalledVersionStillQualifiesForAllViaRawComparison(): void {
		$selector = new CandidateSelector();

		$this->assertSame('2.4.0', $selector->select('2.3', ['2.4.0'], Policy::LEVEL_ALL));
	}

	/**
	 * pinning-ranges-and-skips: a skipped version is passed over and the next qualifying one chosen.
	 */
	public function testASkippedVersionIsPassedOverForTheNextOne(): void {
		$selector = new CandidateSelector();

		$this->assertSame('2.3.5', $selector->select('2.3.3', ['2.3.4', '2.3.5'], Policy::LEVEL_PATCH, ['2.3.4']));
		$this->assertNull($selector->select('2.3.3', ['2.3.4'], Policy::LEVEL_PATCH, ['2.3.4']));
		$this->assertSame('2.3.4', $selector->select('2.3.3', ['2.3.4'], Policy::LEVEL_PATCH, ['2.3.9']));
	}

	/**
	 * The range rule, public so a policy that holds manual installs applies the same rule.
	 */
	public function testWithinLevelIsTheRangeRule(): void {
		$selector = new CandidateSelector();

		$this->assertTrue($selector->withinLevel('2.3.0', '2.3.9', Policy::LEVEL_PATCH));
		$this->assertFalse($selector->withinLevel('2.3.0', '2.4.0', Policy::LEVEL_PATCH));
		$this->assertTrue($selector->withinLevel('2.3.0', '2.9.0', Policy::LEVEL_MINOR));
		$this->assertFalse($selector->withinLevel('2.3.0', '3.0.0', Policy::LEVEL_MINOR));
		$this->assertFalse($selector->withinLevel('2.3.0', '2.3.1-rc1', Policy::LEVEL_PATCH));
		$this->assertTrue($selector->withinLevel('2.3.0', '9.0.0', Policy::LEVEL_ALL));
	}
}
