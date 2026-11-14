<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Policy;

use InvalidArgumentException;
use OCA\Versioniq\Service\Policy\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase {
	public function testConstructAcceptsEachValidLevel(): void {
		foreach (Policy::VALID_LEVELS as $level) {
			$policy = new Policy($level, 'alice', '2026-07-23T00:00:00+00:00');
			$this->assertSame($level, $policy->level);
		}
	}

	public function testConstructRejectsAnInvalidLevel(): void {
		$this->expectException(InvalidArgumentException::class);
		new Policy('yolo', 'alice', '2026-07-23T00:00:00+00:00');
	}

	public function testConstructRejectsEmptySetBy(): void {
		$this->expectException(InvalidArgumentException::class);
		new Policy(Policy::LEVEL_PATCH, '', '2026-07-23T00:00:00+00:00');
	}

	public function testConstructRejectsEmptySetAt(): void {
		$this->expectException(InvalidArgumentException::class);
		new Policy(Policy::LEVEL_PATCH, 'alice', '');
	}

	public function testIsValidLevel(): void {
		$this->assertTrue(Policy::isValidLevel('patch'));
		$this->assertTrue(Policy::isValidLevel('none'));
		$this->assertFalse(Policy::isValidLevel('yolo'));
		$this->assertFalse(Policy::isValidLevel(''));
	}

	public function testToArrayRoundTripsThroughFromArray(): void {
		$policy = new Policy(Policy::LEVEL_MINOR, 'alice', '2026-07-23T00:00:00+00:00');
		$restored = Policy::fromArray($policy->toArray());

		$this->assertSame($policy->level, $restored->level);
		$this->assertSame($policy->setBy, $restored->setBy);
		$this->assertSame($policy->setAt, $restored->setAt);
	}

	public function testFromArrayRejectsMissingFields(): void {
		$this->expectException(InvalidArgumentException::class);
		Policy::fromArray(['level' => 'patch']);
	}

	/**
	 * pinning-ranges-and-skips: a stored policy without the switch reads as off, and the switch round-trips.
	 */
	public function testAnOldPolicyReadsHoldManualAsOff(): void {
		$old = Policy::fromArray(['level' => 'patch', 'setBy' => 'admin', 'setAt' => '2026-01-01T00:00:00+00:00']);
		$this->assertFalse($old->holdManual);
		$this->assertFalse($old->holdsManualInstalls());

		$held = Policy::fromArray(['level' => 'minor', 'setBy' => 'admin', 'setAt' => '2026-01-01T00:00:00+00:00', 'holdManual' => true]);
		$this->assertTrue($held->holdsManualInstalls());
		$this->assertTrue($held->toArray()['holdManual']);
		$this->assertEquals($held, Policy::fromArray($held->toArray()));

		// A non-boolean is not taken for "on".
		$this->assertFalse(Policy::fromArray(['level' => 'patch', 'setBy' => 'a', 'setAt' => 'b', 'holdManual' => 'yes'])->holdManual);
	}

	/**
	 * Only `patch` and `minor` hold manual installs: `none` and `all` have no range to hold to.
	 */
	public function testOnlyPatchAndMinorHoldManualInstalls(): void {
		foreach (['none' => false, 'patch' => true, 'minor' => true, 'all' => false] as $level => $holds) {
			$this->assertSame($holds, (new Policy($level, 'admin', '2026-01-01T00:00:00+00:00', true))->holdsManualInstalls(), $level);
		}
	}
}
