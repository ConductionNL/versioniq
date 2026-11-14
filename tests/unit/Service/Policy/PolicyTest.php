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

	public function testAPolicyRoundTripsWithAndWithoutMinAgeDays(): void {
		$without = new Policy('patch', 'alice', '2026-11-14T10:00:00+00:00');
		$with = new Policy('minor', 'alice', '2026-11-14T10:00:00+00:00', 7);

		$this->assertArrayNotHasKey('minAgeDays', $without->toArray());
		$this->assertNull(Policy::fromArray($without->toArray())->minAgeDays);
		$this->assertSame(7, $with->toArray()['minAgeDays']);
		$this->assertEquals($with, Policy::fromArray($with->toArray()));
	}

	public function testAPolicyRefusesAMinAgeOutsideZeroToNinety(): void {
		foreach ([91, -1] as $days) {
			try {
				new Policy('patch', 'alice', '2026-11-14T10:00:00+00:00', $days);
				$this->fail('accepted ' . $days);
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString('minAgeDays', $e->getMessage());
			}
		}

		$this->expectException(\InvalidArgumentException::class);
		Policy::fromArray(['level' => 'patch', 'setBy' => 'a', 'setAt' => 'b', 'minAgeDays' => '7']);
	}
}
