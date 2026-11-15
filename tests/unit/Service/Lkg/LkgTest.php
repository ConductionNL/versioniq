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

	/**
	 * safety-rollback-backup-and-preflight D1: an update moves the current
	 * record into `previous`; a reinstall of the same version keeps the one it had.
	 */
	public function testAfterAnUpdateTheReplacedRecordIsPrevious(): void {
		$first = Lkg::after(null, '0.4.44', '2026-09-01T08:00:00+00:00', 'appstore');
		self::assertNull($first->previous);

		$second = Lkg::after($first, '0.4.46', '2026-10-01T08:00:00+00:00', 'appstore');
		self::assertSame('0.4.44', $second->previous?->version);

		$third = Lkg::after($second, '0.4.48', '2026-10-15T08:00:00+00:00', null);
		self::assertSame('0.4.46', $third->previous?->version);
		self::assertNull($third->previous?->previous, 'the chain is one deep');

		$reinstall = Lkg::after($third, '0.4.48', '2026-10-16T08:00:00+00:00', null);
		self::assertSame('0.4.46', $reinstall->previous?->version);
		self::assertSame('2026-10-16T08:00:00+00:00', $reinstall->recordedAt);
	}

	public function testToArrayCarriesPreviousOnlyWhenThereIsOne(): void {
		$plain = new Lkg('2.5.0', '2026-07-23T12:00:00+00:00', 'appstore');
		self::assertSame(['version' => '2.5.0', 'recordedAt' => '2026-07-23T12:00:00+00:00', 'sourceId' => 'appstore'], $plain->toArray());

		$nested = new Lkg('2.6.0', '2026-08-01T12:00:00+00:00', null, new Lkg('2.5.0', '2026-07-23T12:00:00+00:00', 'appstore', new Lkg('2.4.0', '2026-06-01T12:00:00+00:00')));
		self::assertSame(
			['version' => '2.6.0', 'recordedAt' => '2026-08-01T12:00:00+00:00', 'sourceId' => null, 'previous' => ['version' => '2.5.0', 'recordedAt' => '2026-07-23T12:00:00+00:00', 'sourceId' => 'appstore']],
			$nested->toArray()
		);
	}

	public function testFromArrayIgnoresAMalformedPrevious(): void {
		$base = ['version' => '2.6.0', 'recordedAt' => '2026-08-01T12:00:00+00:00'];
		self::assertNull(Lkg::fromArray($base + ['previous' => ['version' => '2.5.0; rm -rf', 'recordedAt' => 'x']])->previous);
		self::assertNull(Lkg::fromArray($base + ['previous' => 'nonsense'])->previous);
		self::assertSame('2.5.0', Lkg::fromArray($base + ['previous' => ['version' => '2.5.0', 'recordedAt' => '2026-07-23T12:00:00+00:00', 'previous' => ['version' => '2.4.0', 'recordedAt' => 'y']]])->previous?->version);
		self::assertNull(Lkg::fromArray($base + ['previous' => ['version' => '2.5.0', 'recordedAt' => '2026-07-23T12:00:00+00:00', 'previous' => ['version' => '2.4.0', 'recordedAt' => 'y']]])->previous?->previous);
	}
}
