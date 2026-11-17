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

use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\SourceBinding;

/**
 * Checks an uploaded archive against the App Store (install-manual-upload D1):
 * when the store lists the app and version, and the store's signature for
 * that release verifies the uploaded bytes with a certificate issued to the
 * app, the upload counts as signed. Anything else, a store that cannot be
 * reached included, leaves it unsigned, which is the stricter path.
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.2
 */
class StoreSignatureCheck {
	public function __construct(
		private AppStoreSource $appStore,
		private SelectedReleaseInstallerService $signedInstaller,
	) {
	}

	/**
	 * The App Store release whose signature verifies the archive, or null when
	 * there is none.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.2
	 */
	public function storeRelease(InstallTarget $target, string $archivePath): ?array {
		$release = $this->signedRelease($target->appId, $target->version);
		if ($release === null || !self::verifiesArchive($release, $archivePath)) {
			return null;
		}

		return $this->signedInstaller->trustsCertificate($target->appId, $release['certificate']) ? $release : null;
	}

	/**
	 * The App Store release of this version when it carries a signature and a certificate, or null.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.2
	 */
	private function signedRelease(string $appId, string $version): ?array {
		try {
			$release = $this->appStore->resolveRelease($appId, $version, SourceBinding::appStore());
		} catch (\Throwable) {
			return null;
		}
		if ($release === null || isset($release['error'])) {
			return null;
		}

		$signature = $release['signature'] ?? null;
		$certificate = $release['certificate'] ?? null;

		return is_string($signature) && is_string($certificate) && $signature !== '' && $certificate !== '' ? $release : null;
	}

	/**
	 * Whether the release's signature verifies the archive at `$archivePath`.
	 *
	 * @param array<string, mixed> $release A release with a string signature and certificate.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.2
	 */
	private static function verifiesArchive(array $release, string $archivePath): bool {
		$bytes = @file_get_contents($archivePath);

		return is_string($bytes) && ArchiveSignature::verifies($bytes, $release);
	}
}
