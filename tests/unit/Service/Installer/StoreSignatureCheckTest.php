<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\ArchiveSignature;
use OCA\Versioniq\Service\Installer\StoreSignatureCheck;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * install-manual-upload task 1.2: an upload counts as signed only when the App
 * Store's signature for that app and version verifies its bytes, with a
 * certificate issued to the app.
 *
 * @covers \OCA\Versioniq\Service\Installer\StoreSignatureCheck
 * @covers \OCA\Versioniq\Service\Installer\ArchiveSignature
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.2
 */
final class StoreSignatureCheckTest extends TestCase {
	private const BYTES = "the uploaded archive\x00\x01";

	private string $dir;

	private string $certificate;

	private string $signature;

	/** @var AppStoreSource&MockObject */
	private AppStoreSource $store;

	/** @var SelectedReleaseInstallerService&MockObject */
	private SelectedReleaseInstallerService $signed;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/versioniq-sig-' . bin2hex(random_bytes(6));
		mkdir($this->dir);

		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		self::assertNotFalse($key);
		$csr = openssl_csr_new(['commonName' => 'myapp'], $key);
		self::assertNotFalse($csr);
		$cert = openssl_csr_sign($csr, null, $key, 1);
		self::assertNotFalse($cert);
		openssl_x509_export($cert, $pem);
		$this->certificate = $pem;
		openssl_sign(self::BYTES, $raw, $key, OPENSSL_ALGO_SHA512);
		$this->signature = base64_encode($raw);

		$this->store = $this->createMock(AppStoreSource::class);
		$this->signed = $this->createMock(SelectedReleaseInstallerService::class);
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->dir . '/*') ?: []);
		rmdir($this->dir);
		parent::tearDown();
	}

	public function testAStoreReleaseWhoseSignatureMatchesTheBytesIsReturned(): void {
		$release = $this->release();
		$this->store->expects($this->once())->method('resolveRelease')
			->with('myapp', '2.0.0', $this->callback(static fn (SourceBinding $b): bool => $b->kind === SourceBinding::KIND_APPSTORE))
			->willReturn($release);
		$this->signed->expects($this->once())->method('trustsCertificate')->with('myapp', $this->certificate)->willReturn(true);

		self::assertSame($release, $this->check()->storeRelease('myapp', '2.0.0', $this->archive(self::BYTES)));
	}

	public function testDifferentBytesAreNotSigned(): void {
		$this->store->method('resolveRelease')->willReturn($this->release());
		$this->signed->expects($this->never())->method('trustsCertificate');

		self::assertNull($this->check()->storeRelease('myapp', '2.0.0', $this->archive(self::BYTES . 'patched')));
	}

	public function testACertificateTheCodeSigningChainRefusesIsNotSigned(): void {
		$this->store->method('resolveRelease')->willReturn($this->release());
		$this->signed->method('trustsCertificate')->willReturn(false);

		self::assertNull($this->check()->storeRelease('myapp', '2.0.0', $this->archive(self::BYTES)));
	}

	public function testAVersionTheStoreDoesNotListIsNotSigned(): void {
		$this->store->method('resolveRelease')->willReturn(null);

		self::assertNull($this->check()->storeRelease('myapp', '2.0.0', $this->archive(self::BYTES)));
	}

	public function testAStoreErrorOrUnreachableStoreIsNotSigned(): void {
		$this->store->method('resolveRelease')->willReturnOnConsecutiveCalls(['error' => 'Store down.'], $this->throwException(new RuntimeException('timeout')));

		self::assertNull($this->check()->storeRelease('myapp', '2.0.0', $this->archive(self::BYTES)));
		self::assertNull($this->check()->storeRelease('myapp', '2.0.0', $this->archive(self::BYTES)));
	}

	public function testAReleaseWithoutSignatureOrCertificateIsNotSigned(): void {
		$this->store->method('resolveRelease')->willReturn(['version' => '2.0.0', 'signature' => '', 'certificate' => $this->certificate]);
		$this->signed->expects($this->never())->method('trustsCertificate');

		self::assertNull($this->check()->storeRelease('myapp', '2.0.0', $this->archive(self::BYTES)));
	}

	public function testTheSignatureCheckRefusesGarbage(): void {
		self::assertTrue(ArchiveSignature::verifies(self::BYTES, $this->signature, $this->certificate));
		self::assertFalse(ArchiveSignature::verifies(self::BYTES, 'not base64!', $this->certificate));
		self::assertFalse(ArchiveSignature::verifies(self::BYTES, $this->signature, 'not a certificate'));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function release(): array {
		return ['version' => '2.0.0', 'download' => 'https://example.org/myapp.tar.gz', 'signature' => $this->signature, 'certificate' => $this->certificate];
	}

	private function archive(string $bytes): string {
		$path = $this->dir . '/' . bin2hex(random_bytes(4)) . '.tar.gz';
		file_put_contents($path, $bytes);

		return $path;
	}

	private function check(): StoreSignatureCheck {
		return new StoreSignatureCheck($this->store, $this->signed);
	}
}
