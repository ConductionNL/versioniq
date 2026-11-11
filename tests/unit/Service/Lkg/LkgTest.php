<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Lkg;

use InvalidArgumentException;
use OCA\Versioniq\Service\Lkg\Lkg;
use PHPUnit\Framework\TestCase;

final class LkgTest extends TestCase {
	public function testToArrayRoundTripsThroughFromArray(): void {
		$lkg = new Lkg('2.5.0', '2026-07-23T12:00:00+00:00', 'appstore');

		$restored = Lkg::fromArray($lkg->toArray());

		self::assertSame('2.5.0', $restored->version);
		self::assertSame('2026-07-23T12:00:00+00:00', $restored->recordedAt);
		self::assertSame('appstore', $restored->sourceId);
	}

	public function testSourceIdDefaultsToNull(): void {
		$lkg = new Lkg('2.5.0', '2026-07-23T12:00:00+00:00');

		self::assertNull($lkg->sourceId);
		self::assertNull($lkg->toArray()['sourceId']);
	}

	public function testRejectsEmptyVersion(): void {
		$this->expectException(InvalidArgumentException::class);
		new Lkg('', '2026-07-23T12:00:00+00:00');
	}

	public function testRejectsInvalidVersionCharacters(): void {
		$this->expectException(InvalidArgumentException::class);
		new Lkg('2.5.0; rm -rf', '2026-07-23T12:00:00+00:00');
	}

	public function testRejectsATrailingNewline(): void {
		$this->expectException(InvalidArgumentException::class);
		new Lkg("2.5.0\n", '2026-07-23T12:00:00+00:00');
	}

	public function testRejectsEmptyRecordedAt(): void {
		$this->expectException(InvalidArgumentException::class);
		new Lkg('2.5.0', '');
	}

	public function testFromArrayRejectsMissingFields(): void {
		$this->expectException(InvalidArgumentException::class);
		Lkg::fromArray(['version' => '2.5.0']);
	}

	public function testFromArrayTreatsEmptySourceIdAsNull(): void {
		$lkg = Lkg::fromArray([
			'version' => '2.5.0',
			'recordedAt' => '2026-07-23T12:00:00+00:00',
			'sourceId' => '',
		]);

		self::assertNull($lkg->sourceId);
	}
}
