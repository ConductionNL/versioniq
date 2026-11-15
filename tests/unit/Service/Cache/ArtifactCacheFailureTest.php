<?php

/**
 * Tests for how the artifact cache answers when app data fails underneath it.
 *
 * @category  Test
 * @package   OCA\Versioniq\Tests\Unit\Service\Cache
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Cache;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A failing app data store never breaks a listing, a summary or a fetch: each
 * answers empty and says why in the log ("Cache visibility and management",
 * "Cached fallback with full re-verification").
 *
 * @covers \OCA\Versioniq\Service\Cache\ArtifactCache
 *
 * @spec openspec/specs/artifact-cache/spec.md
 */
final class ArtifactCacheFailureTest extends TestCase {

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $warnings = [];

	/**
	 * The cache over an app data double.
	 *
	 * @param IAppData&MockObject $appData The app data.
	 */
	private function cache(IAppData&MockObject $appData): ArtifactCache {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with(Application::APP_ID)->willReturn($appData);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn(3);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new ArtifactCache($factory, $config, $this->createMock(ITimeFactory::class), $logger);
	}

	public function testAnInvalidAppIdListsNothingAndTouchesNoAppData(): void {
		$appData = $this->createMock(IAppData::class);
		$appData->expects($this->never())->method('getFolder');

		$this->assertSame([], $this->cache($appData)->cachedVersionsFor('../etc'));
		$this->assertSame([], $this->warnings);
	}

	public function testAFailingFolderReadListsNothingAndSaysWhy(): void {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('artifact-cache-myapp')->willThrowException(new RuntimeException('storage offline'));

		$this->assertSame([], $this->cache($appData)->cachedVersionsFor('myapp'));
		$this->assertSame(
			[['ArtifactCache: failed to list cached versions', ['appId' => 'myapp', 'message' => 'storage offline']]],
			$this->warnings
		);
	}

	public function testAFailingAppDataListingIsAnEmptySummary(): void {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getDirectoryListing')->willThrowException(new RuntimeException('listing failed'));

		$this->assertSame(['apps' => [], 'totalSizeBytes' => 0, 'keep' => 3], $this->cache($appData)->summary());
		$this->assertSame([['ArtifactCache: could not list app data', ['message' => 'listing failed']]], $this->warnings);
	}

	public function testATamperedEntryThatCannotBeDeletedIsStillAMissAndEachFailureIsLogged(): void {
		$meta = $this->file(content: json_encode(['sha256' => str_repeat('a', 64)]), deleteFails: true);
		$archive = $this->file(content: 'tampered bytes', deleteFails: true);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(
			static fn (string $name): ISimpleFile => ($name === '1.2.3.meta.json' ? $meta : $archive)
		);
		$folder->method('fileExists')->willReturn(true);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('artifact-cache-myapp')->willReturn($folder);

		$this->assertNull($this->cache($appData)->fetch('myapp', '1.2.3'));
		$this->assertSame(
			[
				['ArtifactCache: cached artifact failed SHA-256 re-verification, discarding', ['appId' => 'myapp', 'version' => '1.2.3']],
				['ArtifactCache: failed to delete cached file', ['name' => '1.2.3.tar.gz', 'message' => 'locked']],
				['ArtifactCache: failed to delete cached file', ['name' => '1.2.3.meta.json', 'message' => 'locked']],
			],
			$this->warnings
		);
	}

	/**
	 * A file double.
	 *
	 * @param string $content     What it holds.
	 * @param bool   $deleteFails Whether deleting it throws.
	 */
	private function file(string $content, bool $deleteFails): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn($content);
		if ($deleteFails) {
			$file->method('delete')->willThrowException(new RuntimeException('locked'));
		}

		return $file;
	}
}
