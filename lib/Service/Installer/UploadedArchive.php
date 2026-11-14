<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Installer;

use InvalidArgumentException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Security\ISecureRandom;

/**
 * An app archive an admin uploaded, held until it is installed or expires
 * (install-manual-upload D1).
 *
 * Inspecting reads only `<app>/appinfo/info.xml` out of the `.tar.gz`, streaming
 * the archive without extracting anything else, and hashes the whole file. The
 * archive and what was read from it are kept in the app data folder `uploads`
 * under a random token, for one hour. Nothing is installed here.
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.1
 */
class UploadedArchive {
	/** The app data folder that holds the uploads. */
	public const FOLDER = 'uploads';

	/** The largest archive accepted: the artifact cache's own read cap. */
	public const MAX_BYTES = 268435456;

	/** How long an inspected upload is kept, in seconds. */
	public const TTL_SECONDS = 3600;

	/** The largest `info.xml` read out of an archive. */
	private const MAX_INFO_XML_BYTES = 1048576;

	/** The shape of a token. */
	private const TOKEN_PATTERN = '/^[a-z0-9]{32}$/D';

	public function __construct(
		private IAppData $appData,
		private ITimeFactory $time,
		private ISecureRandom $random,
	) {
	}

	/**
	 * Read an uploaded archive and keep it under a new token.
	 *
	 * @param string $path The uploaded file.
	 * @return array{token: string, appId: string, version: string, sha256: string}
	 * @throws InvalidArgumentException when the file is too large, not a `.tar.gz`, or has no usable `appinfo/info.xml`
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.1
	 */
	public function store(string $path): array {
		$this->purgeExpired();

		$size = @filesize($path);
		if ($size === false || $size === 0) {
			throw new InvalidArgumentException('The upload is empty or could not be read.');
		}
		if ($size > self::MAX_BYTES) {
			throw new InvalidArgumentException('The archive is larger than 256 MB.');
		}

		[$appId, $version] = self::identity(self::readInfoXml($path));
		$sha256 = (string)hash_file('sha256', $path);
		$token = $this->random->generate(32, ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS);

		$folder = $this->folder();
		$handle = fopen($path, 'rb');
		if ($handle === false) {
			throw new InvalidArgumentException('The upload is empty or could not be read.');
		}
		try {
			$folder->newFile($token . '.tar.gz', $handle);
		} finally {
			fclose($handle);
		}

		$record = ['appId' => $appId, 'version' => $version, 'sha256' => $sha256, 'created' => $this->time->getTime()];
		$folder->newFile($token . '.json', (string)json_encode($record));

		return ['token' => $token, 'appId' => $appId, 'version' => $version, 'sha256' => $sha256];
	}

	/**
	 * What was read from an upload that has not expired; null for an unknown or expired token.
	 *
	 * @return array{appId: string, version: string, sha256: string, created: int}|null
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.1
	 */
	public function inspected(string $token): ?array {
		if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
			return null;
		}

		$record = $this->record($this->folder(), $token);
		if ($record === null) {
			return null;
		}
		if ($this->expired($record['created'])) {
			$this->remove($token);
			return null;
		}

		return $record;
	}

	/**
	 * Drop an upload and what was read from it.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.1
	 */
	public function remove(string $token): void {
		if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
			return;
		}

		$folder = $this->folder();
		foreach ([$token . '.tar.gz', $token . '.json'] as $name) {
			if ($folder->fileExists($name)) {
				$folder->getFile($name)->delete();
			}
		}
	}

	/**
	 * Drop every upload older than an hour.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.1
	 */
	public function purgeExpired(): void {
		$folder = $this->folder();
		foreach ($folder->getDirectoryListing() as $file) {
			$name = $file->getName();
			if (!str_ends_with($name, '.json')) {
				continue;
			}
			$token = substr($name, 0, -5);
			$record = $this->record($folder, $token);
			if ($record === null || $this->expired($record['created'])) {
				$this->remove($token);
			}
		}
	}

	/**
	 * Whether an upload made at $created is older than an hour.
	 */
	private function expired(int $created): bool {
		return $this->time->getTime() - $created >= self::TTL_SECONDS;
	}

	/**
	 * @return array{appId: string, version: string, sha256: string, created: int}|null
	 */
	private function record(ISimpleFolder $folder, string $token): ?array {
		if (!$folder->fileExists($token . '.json')) {
			return null;
		}

		$decoded = json_decode($folder->getFile($token . '.json')->getContent(), true);
		if (!is_array($decoded)) {
			return null;
		}

		$appId = $decoded['appId'] ?? null;
		$version = $decoded['version'] ?? null;
		$sha256 = $decoded['sha256'] ?? null;
		$created = $decoded['created'] ?? null;
		if (!is_string($appId) || !is_string($version) || !is_string($sha256) || !is_int($created)) {
			return null;
		}

		return ['appId' => $appId, 'version' => $version, 'sha256' => $sha256, 'created' => $created];
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}

	/**
	 * The `<app>/appinfo/info.xml` of a `.tar.gz`, read by walking the tar
	 * headers of the decompressed stream; nothing is written to disk.
	 *
	 * @throws InvalidArgumentException when the file is not a `.tar.gz` or holds no such file
	 */
	private static function readInfoXml(string $path): string {
		$stream = @gzopen($path, 'rb');
		if ($stream === false) {
			throw new InvalidArgumentException('The upload is not a .tar.gz archive.');
		}

		try {
			return self::findInfoXml($stream);
		} finally {
			gzclose($stream);
		}
	}

	/**
	 * @param resource $stream A decompressed tar stream.
	 * @throws InvalidArgumentException when no `<app>/appinfo/info.xml` is found
	 */
	private static function findInfoXml($stream): string {
		$longName = null;
		while (true) {
			$header = self::readBlock($stream, 512);
			if ($header === null || trim($header, "\0") === '') {
				break;
			}
			if (substr($header, 257, 5) !== 'ustar' && !self::validChecksum($header)) {
				throw new InvalidArgumentException('The upload is not a .tar.gz archive.');
			}

			$size = self::octal(substr($header, 124, 12));
			if ($size === null) {
				throw new InvalidArgumentException('The upload is not a .tar.gz archive.');
			}
			$type = $header[156];
			$name = $longName ?? self::headerName($header);
			$longName = null;
			$padded = (int)(ceil($size / 512) * 512);

			if ($type === 'L') {
				$longName = rtrim((string)self::readBlock($stream, $padded), "\0");
				continue;
			}
			if (($type === '0' || $type === "\0") && preg_match('#^(\./)?[^/]+/appinfo/info\.xml$#D', $name) === 1) {
				if ($size > self::MAX_INFO_XML_BYTES) {
					throw new InvalidArgumentException('appinfo/info.xml in the archive is too large.');
				}
				return substr((string)self::readBlock($stream, $padded), 0, $size);
			}

			self::skip($stream, $padded);
		}

		throw new InvalidArgumentException('The archive has no appinfo/info.xml.');
	}

	/**
	 * The entry name of a tar header, with its ustar prefix.
	 */
	private static function headerName(string $header): string {
		$name = rtrim(substr($header, 0, 100), "\0");
		$prefix = substr($header, 257, 5) === 'ustar' ? rtrim(substr($header, 345, 155), "\0") : '';

		return $prefix === '' ? $name : $prefix . '/' . $name;
	}

	/**
	 * Whether a header that is not ustar still carries a valid tar checksum.
	 */
	private static function validChecksum(string $header): bool {
		$stored = self::octal(substr($header, 148, 8));
		if ($stored === null) {
			return false;
		}
		$sum = 0;
		for ($i = 0; $i < 512; $i++) {
			$sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
		}

		return $sum === $stored;
	}

	/**
	 * An octal tar header number, or null when the field holds anything else.
	 */
	private static function octal(string $field): ?int {
		$digits = trim($field, "\0 ");
		if ($digits === '' || preg_match('/^[0-7]+$/D', $digits) !== 1) {
			return null;
		}

		return (int)octdec($digits);
	}

	/**
	 * @param resource $stream
	 */
	private static function readBlock($stream, int $length): ?string {
		if ($length === 0) {
			return '';
		}
		$data = '';
		while (strlen($data) < $length && !gzeof($stream)) {
			$chunk = gzread($stream, $length - strlen($data));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
		}

		return strlen($data) === $length ? $data : null;
	}

	/**
	 * @param resource $stream
	 */
	private static function skip($stream, int $length): void {
		while ($length > 0) {
			$chunk = self::readBlock($stream, min($length, 1048576));
			if ($chunk === null) {
				throw new InvalidArgumentException('The archive is truncated.');
			}
			$length -= strlen($chunk);
		}
	}

	/**
	 * The app id and version an `info.xml` declares.
	 *
	 * @return array{0: string, 1: string}
	 * @throws InvalidArgumentException when either is missing or the app id is not a valid one
	 */
	private static function identity(string $infoXml): array {
		$xml = @simplexml_load_string($infoXml, \SimpleXMLElement::class, LIBXML_NONET);
		if (!$xml instanceof \SimpleXMLElement) {
			throw new InvalidArgumentException('appinfo/info.xml in the archive could not be read.');
		}

		$appId = trim((string)$xml->id);
		$version = trim((string)$xml->version);
		if ($appId === '' || !AppIdFormat::isValid($appId)) {
			throw new InvalidArgumentException('appinfo/info.xml in the archive names no valid app id.');
		}
		if ($version === '') {
			throw new InvalidArgumentException('appinfo/info.xml in the archive names no version.');
		}

		return [$appId, $version];
	}
}
