<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Db;

use OCA\Versioniq\Db\Pat;
use PHPUnit\Framework\TestCase;

final class PatTest extends TestCase {
	public function testDefaultsToEmptyLedger(): void {
		$pat = new Pat();

		$this->assertSame([], $pat->getWarnedThresholdsList());
		$this->assertFalse($pat->hasWarnedThreshold('14d'));
	}

	public function testAddWarnedThresholdIsPersistedAndIdempotent(): void {
		$pat = new Pat();

		$pat->addWarnedThreshold('14d');
		$pat->addWarnedThreshold('14d');

		$this->assertSame(['14d'], $pat->getWarnedThresholdsList());
		$this->assertTrue($pat->hasWarnedThreshold('14d'));
		$this->assertFalse($pat->hasWarnedThreshold('3d'));
	}

	public function testAddWarnedThresholdAccumulatesDistinctThresholds(): void {
		$pat = new Pat();

		$pat->addWarnedThreshold('14d');
		$pat->addWarnedThreshold('3d');
		$pat->addWarnedThreshold('expired');

		$this->assertSame(['14d', '3d', 'expired'], $pat->getWarnedThresholdsList());
	}

	public function testClearWarnedThresholdsResetsLedger(): void {
		$pat = new Pat();
		$pat->addWarnedThreshold('14d');
		$pat->addWarnedThreshold('3d');

		$pat->clearWarnedThresholds();

		$this->assertSame([], $pat->getWarnedThresholdsList());
	}

	public function testMalformedLedgerJsonIsTreatedAsEmpty(): void {
		$pat = new Pat();
		$pat->setWarnedThresholds('not-json');

		$this->assertSame([], $pat->getWarnedThresholdsList());
	}
}
