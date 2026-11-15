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

use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\NotifiesAdmins;
use OCA\Versioniq\Service\ResolvesAdminUids;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Rechecks the signing certificate of every installed App Store app against
 * the newest trusted revocation list (design D2 of safety-withdrawn-and-revoked).
 *
 * The certificate is the one in the app's `appinfo/signature.json`. Apps
 * shipped with the server are skipped: their integrity is the server's own
 * check. Forge apps carry no Nextcloud certificate and are skipped too. A
 * newly revoked app notifies every admin once per app and serial; it is
 * disabled, and audited, only when the admin switched that on.
 */
class InstalledCertificateCheck {
	use NotifiesAdmins;
	use ResolvesAdminUids;

	/** The audit operation of an automatic disable. */
	public const OPERATION_DISABLE = 'disable_revoked';

	public function __construct(
		private IAppManager $appManager,
		private SourceBindingStore $bindings,
		private RevocationList $revocationList,
		private RevokedCertificateStore $revoked,
		private InstanceSettings $settings,
		private AuditLogger $auditLogger,
		private AdminNotificationServices $admin,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Checks every installed App Store app; returns the apps checked, those
	 * found revoked and those disabled by this run.
	 *
	 * @return array{checked: list<string>, revoked: list<string>, disabled: list<string>}
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function run(): array {
		$result = ['checked' => [], 'revoked' => [], 'disabled' => []];
		try {
			$list = $this->revocationList->current();
		} catch (\Throwable $error) {
			// No list: change nothing rather than clear every flag.
			$this->logger->warning('InstalledCertificateCheck: no revocation list could be loaded', ['message' => $error->getMessage()]);

			return $result;
		}

		foreach (array_keys($this->appManager->getAppInstalledVersions()) as $appId) {
			$serial = $this->checkedSerial($appId);
			if ($serial === null) {
				continue;
			}

			$result['checked'][] = $appId;
			if ($list->getRevoked($serial) === false) {
				$this->revoked->clear($appId);
				continue;
			}

			$result['revoked'][] = $appId;
			if ($this->handleRevoked($appId, $serial)) {
				$result['disabled'][] = $appId;
			}
		}

		return $result;
	}

	/**
	 * The serial of the app's certificate, or null when the app is not
	 * checked: shipped, bound to a forge, or without a readable certificate.
	 */
	private function checkedSerial(string $appId): ?string {
		if ($this->appManager->isShipped($appId)) {
			return null;
		}

		$binding = $this->bindings->get($appId);
		if ($binding !== null && $binding->getId() !== SourceBinding::KIND_APPSTORE) {
			return null;
		}

		try {
			return self::serialOf($this->appManager->getAppPath($appId) . '/appinfo/signature.json');
		} catch (\Throwable $error) {
			$this->logger->info('InstalledCertificateCheck: the app has no readable certificate', ['app' => $appId, 'message' => $error->getMessage()]);

			return null;
		}
	}

	/**
	 * The decimal serial of the certificate in a signature.json, or null when
	 * the file or its certificate cannot be read.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/tasks.md#2.1
	 */
	public static function serialOf(string $signatureFile): ?string {
		$raw = is_readable($signatureFile) ? file_get_contents($signatureFile) : false;
		/** @var mixed $decoded */
		$decoded = $raw === false ? null : json_decode($raw, true);
		/** @var mixed $certificate */
		$certificate = is_array($decoded) ? ($decoded['certificate'] ?? null) : null;
		$parsed = is_string($certificate) ? openssl_x509_parse($certificate) : false;
		/** @var mixed $serial */
		$serial = is_array($parsed) ? ($parsed['serialNumber'] ?? null) : null;

		return is_string($serial) && $serial !== '' ? $serial : null;
	}

	/**
	 * Notifies once per app and serial, and disables the app when the admin
	 * switched that on; true when this run disabled it.
	 */
	private function handleRevoked(string $appId, string $serial): bool {
		if ($this->revoked->mark($appId, $serial)) {
			$this->notifyAdmins((new AdminNotice())
				->withObject('certificate_revoked', $appId)
				->withSubject('certificate_revoked', ['app' => $appId, 'serial' => $serial])
				->loggedAs('InstalledCertificateCheck: failed to raise the revoked certificate notification', ['appId' => $appId]));
		}

		if (!$this->settings->disableRevoked() || !$this->appManager->isEnabledForAnyone($appId)) {
			return false;
		}

		$version = $this->appManager->getAppVersion($appId);
		try {
			$this->appManager->disableApp($appId);
		} catch (\Throwable $error) {
			$this->auditDisable($appId, $version, AuditLogger::STATUS_FAILURE, 'Disabling the app failed: ' . $error->getMessage());

			return false;
		}

		$this->auditDisable($appId, $version, AuditLogger::STATUS_SUCCESS, sprintf('Disabled: certificate %s was revoked', $serial));

		return true;
	}

	private function auditDisable(string $appId, string $version, string $status, string $message): void {
		$this->auditLogger->record(
			new AuditSubject('', $appId, self::OPERATION_DISABLE),
			new AuditedVersions($version, $version, 'appstore'),
			$status,
			$message,
		);
	}
}
