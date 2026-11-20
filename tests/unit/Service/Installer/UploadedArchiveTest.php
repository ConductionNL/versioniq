<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use InvalidArgumentException;
use OCA\Versioniq\Service\Installer\UploadedArchive;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ISecureRandom;
use PharData;
use PHPUnit\Framework\TestCase;

/**
 * install-manual-upload task 1.1: an uploaded archive is read without being
 * installed, and kept for an hour under a token.
 *
 * @covers \OCA\Versioniq\Service\Installer\UploadedArchive
 * @uses   \OCA\Versioniq\Service\Installer\AppIdFormat
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.1
 */
final class UploadedArchiveTest extends TestCase {
	/** @var array<string, string> The app data folder `uploads`, by file name. */
	private array $files = [];

	private int $now = 1_800_000_000;

	private bool $folderExists = false;

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/versioniq-upload-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	protected function tearDown(): void {
		foreach ((array)glob($this->dir . '/*') as $file) {
			@unlink((string)$file);
		}
		@rmdir($this->dir);
		parent::tearDown();
	}

	public function testAValidArchiveIsReadAndKept(): void {
		$path = $this->archive(['myapp/appinfo/info.xml' => self::infoXml('myapp', '2.1.0'), 'myapp/lib/A.php' => '<?php']);

		$result = $this->uploads()->store($path);

		self::assertSame('abcdefghijklmnopqrstuvwxyz012345', $result['token']);
		self::assertSame('myapp', $result['appId']);
		self::assertSame('2.1.0', $result['version']);
		self::assertSame(hash_file('sha256', $path), $result['sha256']);
		self::assertSame((string)file_get_contents($path), $this->files[$result['token'] . '.tar.gz']);
		self::assertSame(
			['appId' => 'myapp', 'version' => '2.1.0', 'sha256' => $result['sha256'], 'created' => $this->now],
			$this->uploads()->inspected($result['token']),
		);
		self::assertTrue($this->folderExists);
	}

	public function testOnlyTheInfoXmlOfTheAppFolderCounts(): void {
		$path = $this->archive([
			'myapp/tests/fixture/appinfo/info.xml' => self::infoXml('other', '9.9.9'),
			'myapp/appinfo/info.xml' => self::infoXml('myapp', '1.0.0'),
		]);

		self::assertSame('myapp', $this->uploads()->store($path)['appId']);
	}

	public function testAnArchiveWithoutInfoXmlIsRefused(): void {
		$path = $this->archive(['myapp/lib/A.php' => '<?php']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The archive has no appinfo/info.xml.');
		$this->uploads()->store($path);
	}

	public function testAnInfoXmlWithoutAValidIdOrVersionIsRefused(): void {
		$cases = [
			'no id' => ['<info><version>1.0.0</version></info>', 'appinfo/info.xml in the archive names no valid app id.'],
			'bad id' => [self::infoXml('../evil', '1.0.0'), 'appinfo/info.xml in the archive names no valid app id.'],
			'no version' => ['<info><id>myapp</id></info>', 'appinfo/info.xml in the archive names no version.'],
			'not xml' => ['not xml', 'appinfo/info.xml in the archive could not be read.'],
		];
		foreach ($cases as $name => [$xml, $message]) {
			try {
				$this->uploads()->store($this->archive(['myapp/appinfo/info.xml' => $xml], $name));
				self::fail($name . ' was accepted');
			} catch (InvalidArgumentException $error) {
				self::assertSame($message, $error->getMessage(), $name);
			}
		}

		self::assertSame([], $this->files);
	}

	public function testAFileThatIsNotATarGzIsRefused(): void {
		$path = $this->dir . '/plain.txt';
		file_put_contents($path, str_repeat('not an archive ', 100));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The upload is not a .tar.gz archive.');
		$this->uploads()->store($path);
	}

	public function testAnArchiveOverTheCapIsRefusedBeforeItIsRead(): void {
		$path = $this->dir . '/big.tar.gz';
		$handle = fopen($path, 'wb');
		self::assertIsResource($handle);
		ftruncate($handle, UploadedArchive::MAX_BYTES + 1);
		fclose($handle);

		try {
			$this->uploads()->store($path);
			self::fail('an archive over the cap was accepted');
		} catch (InvalidArgumentException $error) {
			self::assertSame('The archive is larger than 256 MB.', $error->getMessage());
		}
		self::assertSame([], $this->files);
	}

	public function testAnUploadExpiresAfterAnHour(): void {
		$token = $this->uploads()->store($this->archive(['myapp/appinfo/info.xml' => self::infoXml('myapp', '1.0.0')]))['token'];

		$this->now += UploadedArchive::TTL_SECONDS - 1;
		self::assertNotNull($this->uploads()->inspected($token));

		$this->now += 1;
		self::assertNull($this->uploads()->inspected($token));
		self::assertSame([], $this->files);
	}

	public function testStoringPurgesExpiredUploads(): void {
		$this->files['oldtoken0000000000000000000000ab.tar.gz'] = 'x';
		$this->files['oldtoken0000000000000000000000ab.json'] = (string)json_encode(['appId' => 'a', 'version' => '1', 'sha256' => 's', 'created' => $this->now - UploadedArchive::TTL_SECONDS]);

		$this->uploads()->store($this->archive(['myapp/appinfo/info.xml' => self::infoXml('myapp', '1.0.0')]));

		self::assertArrayNotHasKey('oldtoken0000000000000000000000ab.tar.gz', $this->files);
		self::assertArrayNotHasKey('oldtoken0000000000000000000000ab.json', $this->files);
	}

	public function testAnUnknownOrMalformedTokenReadsAsNothing(): void {
		self::assertNull($this->uploads()->inspected('abcdefghijklmnopqrstuvwxyz012345'));
		self::assertNull($this->uploads()->inspected('../../etc/passwd'));

		$this->uploads()->remove('../../etc/passwd');
		self::assertSame([], $this->files);
	}

	public function testRemoveDropsTheArchiveAndItsRecord(): void {
		$token = $this->uploads()->store($this->archive(['myapp/appinfo/info.xml' => self::infoXml('myapp', '1.0.0')]))['token'];

		$this->uploads()->remove($token);

		self::assertSame([], $this->files);
	}

	private static function infoXml(string $id, string $version): string {
		return '<?xml version="1.0"?><info><id>' . $id . '</id><name>App</name><version>' . $version . '</version></info>';
	}

	/**
	 * A `.tar.gz` holding the given files.
	 *
	 * @param array<string, string> $entries
	 */
	private function archive(array $entries, string $name = 'app'): string {
		$tar = $this->dir . '/' . md5($name) . '.tar';
		$phar = new PharData($tar);
		foreach ($entries as $entry => $content) {
			$phar->addFromString($entry, $content);
		}
		$phar->compress(\Phar::GZ);
		unset($phar);
		@unlink($tar);

		return $tar . '.gz';
	}

	private function uploads(): UploadedArchive {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->with(32, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS)->willReturn('abcdefghijklmnopqrstuvwxyz012345');

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(function (string $name): ISimpleFolder {
			self::assertSame(UploadedArchive::FOLDER, $name);
			if (!$this->folderExists) {
				throw new NotFoundException();
			}
			return $this->folder();
		});
		$appData->method('newFolder')->willReturnCallback(function (string $name): ISimpleFolder {
			self::assertSame(UploadedArchive::FOLDER, $name);
			$this->folderExists = true;
			return $this->folder();
		});

		return new UploadedArchive($appData, $time, $random);
	}

	/**
	 * The `uploads` folder over $this->files.
	 */
	private function folder(): ISimpleFolder {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn (string $name): bool => isset($this->files[$name]));
		$folder->method('getFile')->willReturnCallback(fn (string $name): ISimpleFile => $this->file($name));
		$folder->method('newFile')->willReturnCallback(function (string $name, $content = null): ISimpleFile {
			$this->files[$name] = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;
			return $this->file($name);
		});
		$folder->method('getDirectoryListing')->willReturnCallback(
			fn (): array => array_map(fn (string $name): ISimpleFile => $this->file($name), array_keys($this->files)),
		);

		return $folder;
	}

	private function file(string $name): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$name] ?? throw new NotFoundException());
		$file->method('delete')->willReturnCallback(function () use ($name): void {
			unset($this->files[$name]);
		});

		return $file;
	}
}
