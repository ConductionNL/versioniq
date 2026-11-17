<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\UpdateType;
use PHPUnit\Framework\TestCase;

final class UpdateTypeTest extends TestCase {
	public function testASameMinorStepIsAPatch(): void {
		$this->assertSame(UpdateType::PATCH, UpdateType::classify('2.3.0', '2.3.4'));
	}

	public function testASameMajorStepIsAMinor(): void {
		$this->assertSame(UpdateType::MINOR, UpdateType::classify('2.3.0', '2.4.0'));
	}

	public function testADifferentMajorIsAMajor(): void {
		$this->assertSame(UpdateType::MAJOR, UpdateType::classify('2.3.0', '3.0.0'));
	}

	public function testANonSemverVersionCountsAsMajor(): void {
		$this->assertSame(UpdateType::MAJOR, UpdateType::classify('2.3.0', 'nightly'));
		$this->assertSame(UpdateType::MAJOR, UpdateType::classify('unknown', '2.3.1'));
	}
}
