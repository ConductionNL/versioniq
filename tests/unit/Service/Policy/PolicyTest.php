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
		$this->assertTrue(Policy::isValidLevel('security'), 'Security fixes only (autoupdate-security-first 1.1)');
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
}
