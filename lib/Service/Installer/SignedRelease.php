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
	/** The release fields, in constructor order, and the error when one is missing or empty. */
	private const REQUIRED_FIELDS = [
		'download' => 'No download URL found for the selected release.',
		'signature' => 'No signature found for the selected release.',
		'certificate' => 'No app certificate found for the selected app.',
		'version' => 'Selected release version is missing.',
	];

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
		$fields = [];
		foreach (self::REQUIRED_FIELDS as $key => $missing) {
			/** @var mixed $value */
			$value = $release[$key] ?? '';
			if (!is_string($value) || $value === '') {
				throw new Exception($missing);
			}
			$fields[] = $value;
		}

		return new self(...$fields);
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
