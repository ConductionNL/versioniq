<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
 */

namespace OCA\Versioniq\Tests\Unit\Service\Inventory;

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Inventory\SbomArchive;
use OCA\Versioniq\Service\Inventory\SbomExporter;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The SBOM kept after each install (inventory-export-sbom-metrics task 3.1):
 * stored under the newest successful install row of the app, pruned to the
 * newest `sbom.keep`, read back by row id, and never throwing.
 *
 * @covers \OCA\Versioniq\Service\Inventory\SbomArchive
 * @uses \OCA\Versioniq\Db\AuditEntry
 */
final class SbomArchiveTest extends TestCase {
	/** @var array<string, string> name => content of the files in the `sbom` folder */
	private array $files = [];

	private bool $folderExists = true;

	/** @var list<string> */
	private array $deleted = [];

	private function file(string $name): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$name]);
		$file->method('delete')->willReturnCallback(function () use ($name): void {
			unset($this->files[$name]);
			$this->deleted[] = $name;
		});

		return $file;
	}

	private function folder(): ISimpleFolder {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('newFile')->willReturnCallback(function (string $name, string $content): ISimpleFile {
			$this->files[$name] = $content;

			return $this->file($name);
		});
		$folder->method('getDirectoryListing')->willReturnCallback(fn (): array => array_map(fn (string $name): ISimpleFile => $this->file($name), array_keys($this->files)));
		$folder->method('getFile')->willReturnCallback(function (string $name): ISimpleFile {
			if (!isset($this->files[$name])) {
				throw new NotFoundException($name);
			}

			return $this->file($name);
		});

		return $folder;
	}

	private function archive(?AuditEntry $latest, int $keep = 200, ?SbomExporter $exporter = null, ?LoggerInterface $log = null): SbomArchive {
		$folder = $this->folder();
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('sbom')->willReturnCallback(function () use ($folder): ISimpleFolder {
			if (!$this->folderExists) {
				throw new NotFoundException('sbom');
			}

			return $folder;
		});
		$appData->method('newFolder')->with('sbom')->willReturnCallback(function () use ($folder): ISimpleFolder {
			$this->folderExists = true;

			return $folder;
		});
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('versioniq')->willReturn($appData);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->with('versioniq', 'sbom.keep', 200)->willReturn($keep);
		if ($exporter === null) {
			$exporter = $this->createMock(SbomExporter::class);
			$exporter->method('export')->with('cyclonedx')->willReturn('{"bomFormat":"CycloneDX"}');
		}
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('findLatestSuccessfulInstall')->willReturn($latest);

		return new SbomArchive($factory, $config, $exporter, $mapper, $log ?? $this->createMock(LoggerInterface::class));
	}

	private static function entry(int $id): AuditEntry {
		$entry = new AuditEntry();
		$entry->setId($id);

		return $entry;
	}

	public function testTheSbomIsKeptUnderTheNewestInstallRow(): void {
		$this->folderExists = false;
		$archive = $this->archive(self::entry(42));

		$archive->writeFor('openregister');

		self::assertSame(['42.cdx.json' => '{"bomFormat":"CycloneDX"}'], $this->files);
		self::assertSame('{"bomFormat":"CycloneDX"}', $archive->read(42));
		self::assertNull($archive->read(41));
		self::assertSame([42 => true], $archive->storedIds());
	}

	public function testOnlyTheNewestKeepCopiesStay(): void {
		$this->files = ['3.cdx.json' => 'c', '10.cdx.json' => 'j', '7.cdx.json' => 'g', 'notes.txt' => 'x'];

		$this->archive(self::entry(11), keep: 2)->writeFor('mail');

		self::assertSame(['10.cdx.json', 'notes.txt', '11.cdx.json'], array_keys($this->files));
		self::assertSame(['7.cdx.json', '3.cdx.json'], $this->deleted);
	}

	public function testWithoutAnInstallRowNothingIsWritten(): void {
		$log = $this->createMock(LoggerInterface::class);
		$log->expects(self::once())->method('warning');

		$this->archive(null, log: $log)->writeFor('mail');

		self::assertSame([], $this->files);
	}

	public function testAFailingExportIsLoggedAndNeverThrown(): void {
		$exporter = $this->createMock(SbomExporter::class);
		$exporter->method('export')->willThrowException(new \RuntimeException('apps unreadable'));
		$log = $this->createMock(LoggerInterface::class);
		$log->expects(self::once())->method('warning');

		$this->archive(self::entry(5), exporter: $exporter, log: $log)->writeFor('mail');

		self::assertSame([], $this->files);
	}

	public function testNoFolderMeansNoCopies(): void {
		$this->folderExists = false;
		$archive = $this->archive(self::entry(1));

		self::assertSame([], $archive->storedIds());
		self::assertNull($archive->read(1));
	}

	public function testAKeepBelowOneStillKeepsTheNewest(): void {
		self::assertSame(1, $this->archive(null, keep: 0)->keep());
		self::assertSame(200, $this->archive(null)->keep());
	}
}
