<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use Exception;
use OCA\Versioniq\Service\Installer\SignedRelease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignedRelease::class)]
final class SignedReleaseTest extends TestCase {
	/** @return array<string, mixed> */
	private function completeRelease(): array {
		return [
			'download' => 'https://example.test/app.tar.gz',
			'signature' => 'c2lnbmF0dXJl',
			'certificate' => '-----BEGIN CERTIFICATE-----',
			'version' => '1.2.3',
			'extra' => 'ignored',
		];
	}

	public function testFromReleaseMapsEveryRequiredField(): void {
		$release = SignedRelease::fromRelease($this->completeRelease());

		self::assertSame('https://example.test/app.tar.gz', $release->download);
		self::assertSame('c2lnbmF0dXJl', $release->signature);
		self::assertSame('-----BEGIN CERTIFICATE-----', $release->certificate);
		self::assertSame('1.2.3', $release->version);
	}

	/** @return iterable<string, array{string, mixed, string}> */
	public static function invalidFields(): iterable {
		yield 'download missing' => ['download', null, 'No download URL found for the selected release.'];
		yield 'download empty' => ['download', '', 'No download URL found for the selected release.'];
		yield 'signature not a string' => ['signature', 42, 'No signature found for the selected release.'];
		yield 'certificate empty' => ['certificate', '', 'No app certificate found for the selected app.'];
		yield 'version array' => ['version', ['1.0'], 'Selected release version is missing.'];
	}

	#[DataProvider('invalidFields')]
	public function testFromReleaseRefusesAMissingOrInvalidField(string $field, mixed $value, string $message): void {
		$release = $this->completeRelease();
		if ($value === null) {
			unset($release[$field]);
		} else {
			$release[$field] = $value;
		}

		$this->expectException(Exception::class);
		$this->expectExceptionMessage($message);

		SignedRelease::fromRelease($release);
	}

	public function testTheFirstMissingFieldInDeclarationOrderIsReported(): void {
		$this->expectExceptionMessage('No download URL found for the selected release.');

		SignedRelease::fromRelease([]);
	}

	public function testWithStoredMaterialsReplacesCertificateAndSignatureOnly(): void {
		$original = SignedRelease::fromRelease($this->completeRelease());

		$stored = $original->withStoredMaterials('stored-cert', 'stored-sig');

		self::assertNotSame($original, $stored);
		self::assertSame('stored-cert', $stored->certificate);
		self::assertSame('stored-sig', $stored->signature);
		self::assertSame('https://example.test/app.tar.gz', $stored->download);
		self::assertSame('1.2.3', $stored->version);
		self::assertSame('c2lnbmF0dXJl', $original->signature);
		self::assertSame('-----BEGIN CERTIFICATE-----', $original->certificate);
	}
}
