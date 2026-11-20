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

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\NotifiesAdmins;
use OCA\Versioniq\Service\ResolvesAdminUids;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What happens when the recheck finds a revoked certificate
 * (safety-withdrawn-and-revoked, design D2): the app is recorded for its
 * "Certificate revoked" badge and every admin gets one `certificate_revoked`
 * notification per app and certificate. Only when an admin switched
 * `safety.disable_revoked` on is the app also disabled, once, with an audit
 * row; an admin who enables it again is not overruled the next day.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 *
 * @psalm-api
 */
class RevokedAppResponder {
	use NotifiesAdmins;
	use ResolvesAdminUids;

	/** Whether an app with a revoked certificate is disabled automatically; off by default. */
	public const KEY_DISABLE = 'safety.disable_revoked';

	public function __construct(
		private RevokedCertificateStore $store,
		private IAppManager $appManager,
		private AuditLogger $auditLogger,
		private IAppConfig $config,
		private AdminNotificationServices $admin,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Records the recheck's results and acts on every newly revoked certificate.
	 *
	 * @param array<string, ?string> $results Each checked app with its revoked serial, or null.
	 * @return list<string> The apps found revoked for the first time.
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function respond(array $results): array {
		$newlyRevoked = [];
		foreach ($results as $appId => $serial) {
			if ($serial === null) {
				$this->store->clear($appId);
				continue;
			}
			if ($this->store->mark($appId, $serial)) {
				$this->respondTo($appId, $serial);
				$newlyRevoked[] = $appId;
			}
		}

		return $newlyRevoked;
	}

	/**
	 * Whether apps with a revoked certificate are disabled automatically.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function disablesRevoked(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_DISABLE, false);
	}

	/**
	 * Disables the app when the switch is on, then tells the admins.
	 */
	private function respondTo(string $appId, string $serial): void {
		$disabled = $this->disablesRevoked() && $this->disable($appId, $serial);
		$this->notifyAdmins((new AdminNotice())
			->withObject('certificate_revoked', $appId)
			->withSubject('certificate_revoked', ['app' => $appId, 'serial' => $serial, 'disabled' => $disabled ? '1' : '0'])
			->loggedAs('RevokedAppResponder: failed to raise the revoked certificate notification', ['appId' => $appId]));
	}

	/**
	 * Disables an enabled app and writes the audit row.
	 *
	 * @return bool Whether the app was disabled.
	 */
	private function disable(string $appId, string $serial): bool {
		if (!$this->appManager->isEnabledForAnyone($appId)) {
			return false;
		}

		try {
			$version = $this->appManager->getAppVersion($appId);
			$this->appManager->disableApp($appId);
		} catch (Throwable $error) {
			$this->logger->warning('RevokedAppResponder: could not disable an app with a revoked certificate', ['appId' => $appId, 'message' => $error->getMessage()]);

			return false;
		}

		$this->auditLogger->record(
			new AuditSubject('system', $appId, AuditLogger::OPERATION_REVOKED_DISABLE),
			new AuditedVersions($version, null, null),
			AuditLogger::STATUS_SUCCESS,
			sprintf('Disabled because Nextcloud revoked its signing certificate (serial %s).', $serial),
		);

		return true;
	}
}
