<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\PlanRun;
use OCA\Versioniq\Service\AutoUpdate\UpdateType;
use OCA\Versioniq\Service\Policy\PatchPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\AutoUpdate\PlanRun
 * @uses   \OCA\Versioniq\Service\AutoUpdate\UpdateType
 * @uses   \OCA\Versioniq\Service\Policy\PatchPolicy
 */
final class PlanRunDueKindsTest extends TestCase {
	private function planRun(): PlanRun {
		return new PlanRun([], false, PatchPolicy::none());
	}

	public function testEveryKindIsDueByDefault(): void {
		$run = $this->planRun();

		$this->assertTrue($run->isDue('2.3.0', '2.3.4'));
		$this->assertTrue($run->isDue('2.3.0', '2.4.0'));
		$this->assertTrue($run->isDue('2.3.0', '3.0.0'));
	}

	public function testOnlyDueKindsQualify(): void {
		$patchOnly = $this->planRun()->withDueKinds([UpdateType::PATCH]);
		$this->assertTrue($patchOnly->isDue('2.3.0', '2.3.4'));
		$this->assertFalse($patchOnly->isDue('2.3.0', '2.4.0'));

		$noMajor = $this->planRun()->withDueKinds([UpdateType::PATCH, UpdateType::MINOR]);
		$this->assertFalse($noMajor->isDue('2.3.0', '3.0.0'));
		$this->assertTrue($this->planRun()->withDueKinds([UpdateType::MAJOR])->isDue('2.3.0', '3.0.0'));
	}

	public function testWithDueKindsLeavesTheOriginalRunAlone(): void {
		$run = $this->planRun();
		$run->withDueKinds([]);

		$this->assertTrue($run->isDue('2.3.0', '2.3.4'));
		$this->assertFalse($run->withDueKinds([])->isDue('2.3.0', '2.3.4'));
	}
}
