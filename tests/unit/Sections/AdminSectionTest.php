<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Sections;

use OCA\Versioniq\Sections\AdminSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class AdminSectionTest extends TestCase {
	private function build(): AdminSection {
		$l = $this->createMock(IL10N::class);
		// Faithful to OC\L10N\L10NString: vsprintf against the parameter array.
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturn('/apps/versioniq/img/app.svg');

		return new AdminSection($l, $urlGenerator);
	}

	public function testGetIdIsAppId(): void {
		self::assertSame('versioniq', $this->build()->getID());
	}

	public function testGetNameIsNonEmpty(): void {
		self::assertNotSame('', $this->build()->getName());
	}

	public function testGetPriorityIsInt(): void {
		self::assertIsInt($this->build()->getPriority());
	}

	public function testGetIconIsNonEmpty(): void {
		self::assertNotSame('', $this->build()->getIcon());
	}
}
