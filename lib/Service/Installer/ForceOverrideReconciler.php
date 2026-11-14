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

use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\NotifiesAdmins;
use OCA\Versioniq\Service\ResolvesAdminUids;
use OCP\App\IAppManager;
use OCP\ServerVersion;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lifts the overrides Versioniq set once the server runs a higher major, as
 * Nextcloud's own updater does since nextcloud/server#63446: it removes the
 * `app_install_overwrite` entry Versioniq added (an entry already gone means
 * Nextcloud lifted it), disables the app when its installed version does not
 * declare support for the new major, drops the record, writes the audit row
 * and tells the admins. An entry without a Versioniq record is never touched.
 *
 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
 */
class ForceOverrideReconciler {
	use NotifiesAdmins;
	use ResolvesAdminUids;

	public function __construct(
		private ForceOverrideStore $overrides,
		private IAppManager $appManager,
		private ServerVersion $serverVersion,
		private AuditLogger $auditLogger,
		private AdminNotificationServices $admin,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Lifts every override set on a lower major than the running one.
	 *
	 * @return list<string> The app ids whose override was lifted.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public function reconcile(): array {
		$major = $this->serverVersion->getMajorVersion();
		$lifted = [];
		foreach ($this->overrides->all() as $appId => $override) {
			if ($override->serverMajor >= $major) {
				continue;
			}
			try {
				$this->lift($appId, $override, $major);
				$lifted[] = $appId;
			} catch (Throwable $error) {
				$this->logger->warning('ForceOverrideReconciler: failed to lift an override', [
					'appId' => $appId,
					'message' => $error->getMessage(),
				]);
			}
		}

		return $lifted;
	}

	private function lift(string $appId, ForceOverride $override, int $major): void {
		if ($override->addedByVersioniq) {
			$this->overrides->remove($appId);
		}
		$disabled = $this->disableWhenUnsupported($appId);
		$this->overrides->forget($appId);
		$this->auditLogger->record(
			new AuditSubject('system', $appId, AuditLogger::OPERATION_FORCE_INCOMPATIBLE),
			new AuditedVersions($override->version, null, null),
			AuditLogger::STATUS_SUCCESS,
			'Lifted after the upgrade to Nextcloud ' . $major . ($disabled ? '; the app was disabled.' : '.'),
		);
		$this->notifyAdmins((new AdminNotice())
			->withObject('force_lifted', $appId)
			->withSubject('force_lifted', ['app' => $appId, 'major' => (string)$major, 'disabled' => $disabled ? '1' : '0'])
			->loggedAs('ForceOverrideReconciler: failed to raise the lift notification', ['appId' => $appId]));
	}

	/**
	 * Disables an enabled app whose installed version does not declare support
	 * for the running server, the state Nextcloud's updater leaves such an app
	 * in. An unreadable range leaves the app as it is.
	 */
	private function disableWhenUnsupported(string $appId): bool {
		if (!$this->appManager->isEnabledForAnyone($appId)) {
			return false;
		}
		$supported = DeclaredServerRange::supports($this->serverVersion->getVersionString(), $this->appManager->getAppInfo($appId));
		if ($supported !== false) {
			return false;
		}
		$this->appManager->disableApp($appId);

		return true;
	}
}
