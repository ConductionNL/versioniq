<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\BundledLibraryScanner;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Advisory\BundledLibraryScanner
 */
final class BundledLibraryScannerTest extends TestCase {
	private const FIXTURES = __DIR__ . '/fixtures/bundled';

	private function scanner(string $fixture): BundledLibraryScanner {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->once())->method('getAppPath')->with('mail')->willReturn(self::FIXTURES . '/' . $fixture);

		return new BundledLibraryScanner($appManager);
	}

	public function testReadsTheComposerTwoLayoutAndLeavesOutDevelopmentPackagesAndBranches(): void {
		$result = $this->scanner('composer2')->scan('mail');

		$this->assertSame([
			'manifest' => 'vendor/composer/installed.json',
			'libraries' => [
				['name' => 'vendor/library', 'version' => '2.4.3'],
				// The leading `v` of a tag is stripped.
				['name' => 'vendor/tagged', 'version' => '1.2.0'],
			],
			// `dev-main` and the package in `dev-package-names`.
			'skipped' => 2,
		], $result);
	}

	public function testReadsTheComposerOneLayout(): void {
		$result = $this->scanner('composer1')->scan('mail');

		$this->assertSame([
			'manifest' => 'vendor/composer/installed.json',
			'libraries' => [
				['name' => 'vendor/legacy', 'version' => '1.0.1'],
				['name' => 'vendor/other', 'version' => '3.0.0'],
			],
			'skipped' => 0,
		], $result);
	}

	public function testAnAppWithoutAManifestHasNone(): void {
		$this->assertSame(['manifest' => null, 'libraries' => [], 'skipped' => 0], $this->scanner('nomanifest')->scan('mail'));
	}

	public function testAnUnreadableManifestListsNothingButIsStillNamed(): void {
		$this->assertSame(
			['manifest' => 'vendor/composer/installed.json', 'libraries' => [], 'skipped' => 0],
			$this->scanner('broken')->scan('mail'),
		);
	}

	public function testAnAppWhosePathIsUnknownHasNoManifest(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willThrowException(new AppPathNotFoundException('gone'));

		$this->assertSame(['manifest' => null, 'libraries' => [], 'skipped' => 0], (new BundledLibraryScanner($appManager))->scan('gone'));
	}

	public function testParseIgnoresEntriesWithoutANameOrVersion(): void {
		$scanner = new BundledLibraryScanner($this->createMock(IAppManager::class));
		$raw = json_encode(['packages' => [['name' => 'vendor/a'], ['version' => '1.0.0'], 'text', ['name' => 'vendor/b', 'version' => '2.0.0']]], JSON_THROW_ON_ERROR);

		$this->assertSame(['libraries' => [['name' => 'vendor/b', 'version' => '2.0.0']], 'skipped' => 0], $scanner->parse($raw));
		$this->assertSame(['libraries' => [], 'skipped' => 0], $scanner->parse('"a string"'));
		$this->assertSame(['libraries' => [], 'skipped' => 0], $scanner->parse('{"packages": "none"}'));
	}
}
