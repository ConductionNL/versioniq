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

use Exception;

/**
 * The signed-release fields one App Store install needs: the archive URL, its
 * signature, the app certificate and the version the archive must declare.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class SignedRelease {
	public function __construct(
		public readonly string $download,
		public readonly string $signature,
		public readonly string $certificate,
		public readonly string $version,
	) {
	}

	/**
	 * Reads the fields from a resolved release, rejecting the first one that is missing or empty.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @param array<string, mixed> $release
	 * @throws Exception
	 */
	public static function fromRelease(array $release): self {
		$download = $release['download'] ?? '';
		$signature = $release['signature'] ?? '';
		$certificate = $release['certificate'] ?? '';
		$version = $release['version'] ?? null;

		if (!is_string($download) || $download === '') {
			throw new Exception('No download URL found for the selected release.');
		}
		if (!is_string($signature) || $signature === '') {
			throw new Exception('No signature found for the selected release.');
		}
		if (!is_string($certificate) || $certificate === '') {
			throw new Exception('No app certificate found for the selected app.');
		}
		if (!is_string($version) || $version === '') {
			throw new Exception('Selected release version is missing.');
		}

		return new self($download, $signature, $certificate, $version);
	}

	/**
	 * A copy that verifies against the certificate and signature stored with a cached artifact.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function withStoredMaterials(string $certificate, string $signature): self {
		return new self($this->download, $signature, $certificate, $this->version);
	}
}
