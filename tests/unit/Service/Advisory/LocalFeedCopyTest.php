<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use InvalidArgumentException;
use OCA\Versioniq\Service\Advisory\LocalFeedCopy;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The local copy of the advisory feed (sources-trust-audit-and-offline-feed
 * 3.1): a valid file is stored in app data with its import time, a broken
 * file is refused before anything is written so the previous copy stays, and
 * a copy older than 30 days is stale.
 *
 * @covers \OCA\Versioniq\Service\Advisory\LocalFeedCopy
 *
 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
 */
final class LocalFeedCopyTest extends TestCase {
	/** @var array<string, int> */
	private array $config = [];

	/** The stored feed file's content, or null while there is none. */
	private ?string $stored = null;

	private bool $folderExists = true;

	private function copy(): LocalFeedCopy {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(fn (): string => (string)$this->stored);
		$file->method('putContent')->willReturnCallback(function (string $content): void {
			$this->stored = $content;
		});

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function (string $name) use ($file): ISimpleFile {
			if ($name !== LocalFeedCopy::FILE || $this->stored === null) {
				throw new NotFoundException();
			}
			return $file;
		});
		$folder->method('newFile')->willReturnCallback(function (string $name, string $content) use ($file): ISimpleFile {
			self::assertSame('feed.json', $name);
			$this->stored = $content;
			return $file;
		});

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(function (string $name) use ($folder): ISimpleFolder {
			self::assertSame('advisories', $name);
			if (!$this->folderExists) {
				throw new NotFoundException();
			}
			return $folder;
		});
		$appData->method('newFolder')->willReturnCallback(function (string $name) use ($folder): ISimpleFolder {
			self::assertSame('advisories', $name);
			$this->folderExists = true;
			return $folder;
		});
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('versioniq')->willReturn($appData);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => $this->config[$key] ?? $default,
		);
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			self::assertSame('versioniq', $app);
			$this->config[$key] = $value;
			return true;
		});

		return new LocalFeedCopy($factory, $appConfig);
	}

	private static function feed(string ...$ids): string {
		return json_encode(array_map(static fn (string $id): array => ['ghsa_id' => $id, 'severity' => 'high'], $ids), JSON_THROW_ON_ERROR);
	}

	public function testAValidFileIsStoredWithItsImportTimeAndRecordCount(): void {
		$this->folderExists = false;

		$state = $this->copy()->import(self::feed('GHSA-aaaa', 'GHSA-bbbb'), 1792800000);

		self::assertSame(['copiedAt' => 1792800000, 'records' => 2], $state);
		self::assertSame(1792800000, $this->config['advisory.feed_copy_at']);
		self::assertSame(2, $this->config['advisory.feed_copy_records']);
		self::assertSame([['ghsa_id' => 'GHSA-aaaa', 'severity' => 'high'], ['ghsa_id' => 'GHSA-bbbb', 'severity' => 'high']], $this->copy()->records());
		self::assertSame(1792800000, $this->copy()->copiedAt());
	}

	public function testANewImportReplacesTheStoredCopy(): void {
		$this->copy()->import(self::feed('GHSA-aaaa'), 1792800000);
		$this->copy()->import(self::feed('GHSA-cccc'), 1792900000);

		self::assertSame('GHSA-cccc', $this->copy()->records()[0]['ghsa_id']);
		self::assertSame(1792900000, $this->copy()->copiedAt());
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function brokenFiles(): array {
		return [
			'not JSON' => ['<html>', 'The file is not valid JSON'],
			'an object' => ['{"ghsa_id": "GHSA-aaaa"}', 'The file must hold a list of advisories'],
			'an empty list' => ['[]', 'The file must hold a list of advisories'],
			'an entry without ghsa_id' => ['[{"ghsa_id": "GHSA-aaaa"}, {"summary": "x"}]', 'Every entry in the file must be an advisory with a ghsa_id.'],
			'an empty ghsa_id' => ['[{"ghsa_id": ""}]', 'Every entry in the file must be an advisory with a ghsa_id.'],
		];
	}

	/**
	 * @dataProvider brokenFiles
	 */
	public function testABrokenFileIsRefusedAndThePreviousCopyStays(string $content, string $reason): void {
		$this->copy()->import(self::feed('GHSA-aaaa'), 1792800000);

		try {
			$this->copy()->import($content, 1792900000);
			self::fail('A broken file was accepted');
		} catch (InvalidArgumentException $error) {
			self::assertStringStartsWith($reason, $error->getMessage());
		}

		self::assertSame('GHSA-aaaa', $this->copy()->records()[0]['ghsa_id']);
		self::assertSame(1792800000, $this->config['advisory.feed_copy_at']);
		self::assertSame(1, $this->config['advisory.feed_copy_records']);
	}

	public function testWithoutAnImportThereIsNoCopy(): void {
		self::assertNull($this->copy()->records());
		self::assertNull($this->copy()->copiedAt());
	}

	public function testAStoredCopyThatNoLongerParsesReadsAsNone(): void {
		$this->stored = 'not json';

		self::assertNull($this->copy()->records());
	}

	public function testACopyOlderThanThirtyDaysIsStale(): void {
		$copiedAt = 1792800000;

		self::assertFalse(LocalFeedCopy::isStale($copiedAt, $copiedAt + 30 * 86400));
		self::assertTrue(LocalFeedCopy::isStale($copiedAt, $copiedAt + 30 * 86400 + 1));
		self::assertFalse(LocalFeedCopy::isStale(null, $copiedAt));
	}
}
