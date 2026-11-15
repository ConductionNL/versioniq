<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use Exception;
use OCA\Versioniq\Service\Installer\AppsRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppsRoot::class)]
final class AppsRootTest extends TestCase {
	/** @var mixed */
	private $savedRoots;

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->savedRoots = \OC::$APPSROOTS;
		$this->dir = sys_get_temp_dir() . '/av-appsroot-' . uniqid('', true);
		mkdir($this->dir, 0777, true);
	}

	protected function tearDown(): void {
		\OC::$APPSROOTS = $this->savedRoots;
		@rmdir($this->dir);
		parent::tearDown();
	}

	public function testReturnsTheFirstRootMarkedWritable(): void {
		\OC::$APPSROOTS = [
			['path' => '/nonexistent/readonly-apps', 'url' => '/apps', 'writable' => false],
			['path' => $this->dir, 'url' => '/custom_apps', 'writable' => true],
			['path' => '/nonexistent/second-writable', 'url' => '/more', 'writable' => true],
		];

		self::assertSame($this->dir, AppsRoot::writablePath());
	}

	public function testARootWithoutAStrictTrueWritableFlagIsSkipped(): void {
		\OC::$APPSROOTS = [
			['path' => '/nonexistent/no-flag', 'url' => '/apps'],
			['path' => '/nonexistent/truthy', 'url' => '/apps', 'writable' => 1],
			['path' => $this->dir, 'url' => '/custom_apps', 'writable' => true],
		];

		self::assertSame($this->dir, AppsRoot::writablePath());
	}

	public function testAWritableRootThatIsNotAccessibleOnDiskIsRefused(): void {
		\OC::$APPSROOTS = [
			['path' => $this->dir . '/missing', 'url' => '/custom_apps', 'writable' => true],
			['path' => $this->dir, 'url' => '/other', 'writable' => true],
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Cannot write into "apps" directory.');

		AppsRoot::writablePath();
	}

	public function testNoWritableRootIsRefused(): void {
		\OC::$APPSROOTS = [
			['path' => $this->dir, 'url' => '/apps', 'writable' => false],
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No writable apps directory found.');

		AppsRoot::writablePath();
	}
}
