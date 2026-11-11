<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Pin;

use InvalidArgumentException;
use OCA\Versioniq\Service\Pin\Pin;
use PHPUnit\Framework\TestCase;

final class PinTest extends TestCase {
	public function testConstructorRejectsEmptyVersion(): void {
		$this->expectException(InvalidArgumentException::class);
		new Pin('', 'alice', '2026-06-11T12:00:00+00:00');
	}

	public function testConstructorRejectsInvalidVersionCharacters(): void {
		$this->expectException(InvalidArgumentException::class);
		new Pin('2.3.0; rm -rf', 'alice', '2026-06-11T12:00:00+00:00');
	}

	public function testConstructorRejectsATrailingNewline(): void {
		$this->expectException(InvalidArgumentException::class);
		new Pin("2.3.0\n", 'alice', '2026-06-11T12:00:00+00:00');
	}

	public function testConstructorRejectsEmptyPinnedBy(): void {
		$this->expectException(InvalidArgumentException::class);
		new Pin('2.3.0', '', '2026-06-11T12:00:00+00:00');
	}

	public function testConstructorRejectsEmptyPinnedAt(): void {
		$this->expectException(InvalidArgumentException::class);
		new Pin('2.3.0', 'alice', '');
	}

	public function testConstructorRejectsDriftedToWithoutDriftedAt(): void {
		$this->expectException(InvalidArgumentException::class);
		new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00', null, '2.5.0', null);
	}

	public function testConstructorRejectsDriftedAtWithoutDriftedTo(): void {
		$this->expectException(InvalidArgumentException::class);
		new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00', null, null, '2026-06-12T00:00:00+00:00');
	}

	public function testHasDriftedIsFalseByDefault(): void {
		$pin = new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00');

		$this->assertFalse($pin->hasDrifted());
	}

	public function testWithDriftSetsMarkersAndPreservesOtherFields(): void {
		$pin = new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00', 'reason');
		$drifted = $pin->withDrift('2.5.0', '2026-06-12T00:00:00+00:00');

		$this->assertTrue($drifted->hasDrifted());
		$this->assertSame('2.5.0', $drifted->driftedTo);
		$this->assertSame('2026-06-12T00:00:00+00:00', $drifted->driftedAt);
		$this->assertSame('2.3.0', $drifted->version);
		$this->assertSame('alice', $drifted->pinnedBy);
		$this->assertSame('reason', $drifted->reason);
	}

	public function testWithoutDriftClearsMarkers(): void {
		$pin = (new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00'))
			->withDrift('2.5.0', '2026-06-12T00:00:00+00:00');

		$cleared = $pin->withoutDrift();

		$this->assertFalse($cleared->hasDrifted());
		$this->assertNull($cleared->driftedTo);
		$this->assertNull($cleared->driftedAt);
	}

	public function testToArrayOmitsNullFields(): void {
		$pin = new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00');

		$array = $pin->toArray();

		$this->assertSame(['version' => '2.3.0', 'pinnedBy' => 'alice', 'pinnedAt' => '2026-06-11T12:00:00+00:00'], $array);
	}

	public function testToArrayIncludesReasonAndDrift(): void {
		$pin = (new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00', 'because'))
			->withDrift('2.5.0', '2026-06-12T00:00:00+00:00');

		$array = $pin->toArray();

		$this->assertSame('because', $array['reason']);
		$this->assertSame('2.5.0', $array['driftedTo']);
		$this->assertSame('2026-06-12T00:00:00+00:00', $array['driftedAt']);
	}

	public function testFromArrayRoundTrips(): void {
		$original = (new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00', 'because'))
			->withDrift('2.5.0', '2026-06-12T00:00:00+00:00');

		$restored = Pin::fromArray($original->toArray());

		$this->assertEquals($original, $restored);
	}

	public function testFromArrayThrowsWhenRequiredFieldsMissing(): void {
		$this->expectException(InvalidArgumentException::class);
		Pin::fromArray(['version' => '2.3.0']);
	}

	public function testFromArrayReadsEmptyOptionalFieldsAsUnset(): void {
		$pin = Pin::fromArray(['version' => '2.3.0', 'pinnedBy' => 'admin', 'pinnedAt' => '2026-07-01T00:00:00+00:00', 'reason' => '', 'driftedTo' => '', 'driftedAt' => '']);

		$this->assertNull($pin->reason);
		$this->assertNull($pin->driftedTo);
		$this->assertNull($pin->driftedAt);
	}
}
