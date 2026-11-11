<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\AppIdFormat;
use PHPUnit\Framework\TestCase;

final class AppIdFormatTest extends TestCase {
	public function testAcceptsAnAppId(): void {
		$this->assertTrue(AppIdFormat::isValid('open_register-2'));
	}

	public function testRejectsATrailingNewline(): void {
		$this->assertFalse(AppIdFormat::isValid("openregister\n"));
	}

	public function testRejectsPathCharacters(): void {
		$this->assertFalse(AppIdFormat::isValid('../openregister'));
	}
}
