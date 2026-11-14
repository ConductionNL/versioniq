<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\UpdateType;
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

	public function testOnlyDueKindsQualify(): void {
		$selector = new CandidateSelector();

		$this->assertSame('2.3.4', $selector->select('2.3.0', ['2.3.4', '2.4.0'], Policy::LEVEL_MINOR, [UpdateType::PATCH]));
		$this->assertNull($selector->select('2.3.0', ['3.0.0'], Policy::LEVEL_ALL, [UpdateType::PATCH, UpdateType::MINOR]));
		$this->assertSame('3.0.0', $selector->select('2.3.0', ['2.4.0', '3.0.0'], Policy::LEVEL_ALL, [UpdateType::MAJOR]));
	}
}
