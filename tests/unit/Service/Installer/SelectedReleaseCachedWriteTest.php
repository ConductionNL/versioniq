<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

require_once __DIR__ . '/InstallerGoldenSupport.php';

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Installer\SignedRelease;
use OCA\Versioniq\Service\Installer\SignedReleaseRun;
use OCA\Versioniq\Service\Installer\TempArchive;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCP\IUserSession;
use phpseclib3\File\X509;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The cached-release fallback writes the cached archive to the run's temporary
 * file. When that write fails there is no usable fallback, so the fallback
 * answers null instead of a release whose archive was never written.
 *
 * @covers \OCA\Versioniq\Service\SelectedReleaseInstallerService
 * @uses \OCA\Versioniq\Service\Installer\SignedRelease
 * @uses \OCA\Versioniq\Service\Installer\SignedReleaseRun
 * @uses \OCA\Versioniq\Service\Installer\TempArchive
 */
final class SelectedReleaseCachedWriteTest extends TestCase {
	private string $root = '';

	private string $savedServerRoot = '';

	private static string $certificate = '';

	public static function setUpBeforeClass(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
		$csr = openssl_csr_new(['commonName' => 'someapp'], $key, ['digest_alg' => 'sha256']);
		self::assertNotFalse($csr);
		$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
		self::assertNotFalse($cert);
		openssl_x509_export($cert, $pem);
		self::$certificate = (string)$pem;
	}

	protected function setUp(): void {
		parent::setUp();
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('Needs the scripted X509 stub of the unit-only bootstrap.');
		}
		$this->root = sys_get_temp_dir() . '/versioniq-cached-write-' . bin2hex(random_bytes(6));
		mkdir($this->root . '/resources/codesigning', 0777, true);
		file_put_contents($this->root . '/resources/codesigning/root.crt', "-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n");
		file_put_contents($this->root . '/resources/codesigning/root.crl', 'ROOT-CRL');
		$this->savedServerRoot = (string)\OC::$SERVERROOT;
		\OC::$SERVERROOT = $this->root;
		X509::$handler = static fn (X509 $x509, string $method, array $args): mixed => match ($method) {
			'loadX509' => ['tbsCertificate' => ['serialNumber' => new class {
				public function toString(): string {
					return '4242';
				}
			}]],
			'getRevoked' => false,
			default => true,
		};
	}

	protected function tearDown(): void {
		if ($this->root !== '') {
			X509::$handler = null;
			\OC::$SERVERROOT = $this->savedServerRoot;
			@unlink($this->root . '/download.tar.gz');
			@unlink($this->root . '/resources/codesigning/root.crt');
			@unlink($this->root . '/resources/codesigning/root.crl');
			@rmdir($this->root . '/resources/codesigning');
			@rmdir($this->root . '/resources');
			@rmdir($this->root);
		}
		parent::tearDown();
	}

	public function testAWritableTemporaryFileGetsTheArchiveAndTheStoredMaterials(): void {
		$file = $this->root . '/download.tar.gz';

		[$release, $warnings] = $this->cachedRelease($file);

		self::assertInstanceOf(SignedRelease::class, $release);
		self::assertSame(self::$certificate, $release->certificate);
		self::assertSame('stored-signature', $release->signature);
		self::assertSame('cached bytes', file_get_contents($file));
		self::assertSame([], $warnings);
	}

	public function testAFailedWriteOfTheArchiveGivesNoRelease(): void {
		$file = $this->root . '/no-such-folder/download.tar.gz';

		[$release, $warnings] = $this->cachedRelease($file);

		self::assertNull($release);
		self::assertFileDoesNotExist($file);
		self::assertCount(1, $warnings);
		self::assertStringContainsString('Failed to open stream', $warnings[0]);
	}

	/**
	 * @return array{0: ?SignedRelease, 1: list<string>}
	 */
	private function cachedRelease(string $file): array {
		$cache = $this->createMock(ArtifactCache::class);
		$cache->method('fetch')->with('someapp', '2.0.0')->willReturn([
			'content' => 'cached bytes',
			'meta' => ['certificate' => self::$certificate, 'signature' => 'stored-signature'],
		]);
		$service = new SelectedReleaseInstallerService(
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			$this->createMock(IUserSession::class),
			$this->createMock(MigrationDiffer::class),
			$cache,
		);
		$run = new SignedReleaseRun(
			'someapp',
			new SignedRelease('https://dl.example/a.tar.gz', 'sig', 'cert', '2.0.0'),
			new TempArchive($file, $this->root . '/extract'),
		);

		$warnings = [];
		set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
			$warnings[] = $message;

			return true;
		});
		try {
			$release = (new ReflectionMethod($service, 'cachedRelease'))->invoke($service, $run);
		} finally {
			restore_error_handler();
		}

		return [$release, $warnings];
	}
}
