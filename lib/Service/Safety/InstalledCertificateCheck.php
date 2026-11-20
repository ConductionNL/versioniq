<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Safety;

use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCP\App\IAppManager;
use Throwable;

/**
 * Checks the signing certificate of every installed App Store app against a
 * revocation list (safety-withdrawn-and-revoked, design D2). The certificate
 * is the `certificate` in the app's `appinfo/signature.json`, which every
 * signed App Store app ships. Apps shipped with the server are skipped (their
 * integrity is the server's own check), and so are apps bound to a forge,
 * which carry no Nextcloud certificate, and apps without a readable one.
 *
 * Read-only: it never changes an app.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 *
 * @psalm-api
 */
class InstalledCertificateCheck {
	public function __construct(
		private IAppManager $appManager,
		private SourceBindingStore $bindings,
	) {
	}

	/**
	 * Every checked app with the serial of its revoked certificate, or null
	 * when its certificate is not on the list.
	 *
	 * @return array<string, ?string> By app id.
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function check(CrlDocument $list): array {
		$results = [];
		foreach ($this->checkedAppIds() as $appId) {
			$serial = $this->certificateSerial($appId);
			if ($serial !== null) {
				$results[$appId] = $list->revokes($serial) ? $serial : null;
			}
		}

		return $results;
	}

	/**
	 * The installed apps that are neither shipped nor bound to a forge.
	 *
	 * @return list<string>
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function checkedAppIds(): array {
		$appIds = array_map('strval', array_keys($this->appManager->getAppInstalledVersions(false)));

		return array_values(array_filter($appIds, fn (string $appId): bool => !$this->appManager->isShipped($appId)
			&& ($this->bindings->get($appId)?->kind ?? SourceBinding::KIND_APPSTORE) === SourceBinding::KIND_APPSTORE));
	}

	/**
	 * The serial number (hex) of the certificate in the app's
	 * `appinfo/signature.json`, or null when there is none to read.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function certificateSerial(string $appId): ?string {
		try {
			$path = $this->appManager->getAppPath($appId) . '/appinfo/signature.json';
			$json = is_readable($path) ? file_get_contents($path) : false;
		} catch (Throwable) {
			return null;
		}

		$certificate = $json === false ? '' : self::certificateIn($json);
		$parsed = $certificate === '' ? false : openssl_x509_parse($certificate);
		/** @var mixed $serial */
		$serial = is_array($parsed) ? ($parsed['serialNumberHex'] ?? null) : null;

		return is_string($serial) && $serial !== '' ? DerElement::normalHex($serial) : null;
	}

	/**
	 * The `certificate` of a `signature.json`, or ''.
	 */
	private static function certificateIn(string $json): string {
		/** @var mixed $signature */
		$signature = json_decode($json, true);
		/** @var mixed $certificate */
		$certificate = is_array($signature) ? ($signature['certificate'] ?? null) : null;

		return is_string($certificate) ? $certificate : '';
	}
}
