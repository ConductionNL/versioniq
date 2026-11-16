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

/**
 * Whether an App Store release signature covers an archive's bytes: the
 * base64 SHA-512 RSA signature the store publishes, checked against the
 * public key of the release certificate. That the certificate itself chains
 * to the code-signing root is checked separately, by the signed installer.
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.2
 */
final class ArchiveSignature {
	/**
	 * @param string $bytes The archive.
	 * @param string $signature The release signature, base64.
	 * @param string $certificate The release certificate, PEM.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.2
	 */
	public static function verifies(string $bytes, string $signature, string $certificate): bool {
		$decoded = base64_decode($signature, true);
		if ($decoded === false || $decoded === '') {
			return false;
		}

		$publicKey = @openssl_pkey_get_public($certificate);
		if ($publicKey === false) {
			return false;
		}

		return openssl_verify($bytes, $decoded, $publicKey, OPENSSL_ALGO_SHA512) === 1;
	}
}
