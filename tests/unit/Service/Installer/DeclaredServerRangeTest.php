<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\DeclaredServerRange;
use PHPUnit\Framework\TestCase;

/**
 * The range an installed app declares in its info.xml, and whether only its
 * maximum keeps the server out (install-force-incompatible).
 */
final class DeclaredServerRangeTest extends TestCase {
	/**
	 * @param array<string, string> $attributes
	 * @return array<string, mixed>
	 */
	private static function info(array $attributes): array {
		return ['id' => 'hermiq', 'dependencies' => ['nextcloud' => ['@attributes' => $attributes]]];
	}

	public function testTheDeclaredBoundsBecomeASpec(): void {
		$this->assertSame('>=30 <=32', DeclaredServerRange::specOf(self::info(['min-version' => '30', 'max-version' => '32'])));
		$this->assertSame('>=30', DeclaredServerRange::specOf(self::info(['min-version' => ' 30 '])));
		$this->assertSame('<=32.0.1', DeclaredServerRange::specOf(self::info(['max-version' => '32.0.1', 'min-version' => 'x'])));
		$this->assertNull(DeclaredServerRange::specOf(self::info([])));
		$this->assertNull(DeclaredServerRange::specOf(['id' => 'hermiq']));
		$this->assertNull(DeclaredServerRange::specOf(null));
	}

	public function testAMaximumBelowTheServerIsServerTooNewAndUnsupported(): void {
		$info = self::info(['min-version' => '30', 'max-version' => '32']);

		$this->assertTrue(DeclaredServerRange::serverTooNew('33.0.2', $info));
		$this->assertFalse(DeclaredServerRange::supports('33.0.2', $info));
		$this->assertTrue(DeclaredServerRange::supports('32.0.5', $info));
		$this->assertFalse(DeclaredServerRange::serverTooNew('32.0.5', $info));
	}

	public function testAMinimumAboveTheServerIsNeverServerTooNew(): void {
		$info = self::info(['min-version' => '33']);

		$this->assertFalse(DeclaredServerRange::serverTooNew('32.0.5', $info));
		$this->assertFalse(DeclaredServerRange::supports('32.0.5', $info));
	}

	public function testNoDeclaredRangeIsUnknownAndNotServerTooNew(): void {
		$this->assertNull(DeclaredServerRange::supports('33.0.2', null));
		$this->assertFalse(DeclaredServerRange::serverTooNew('33.0.2', null));
	}
}
