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

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Disables an app whose signing certificate Nextcloud revoked, and writes the
 * audit row (safety-withdrawn-and-revoked, design D2). Only
 * {@see RevokedAppResponder} calls it, and only with `safety.disable_revoked` on.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 *
 * @psalm-api
 */
class RevokedAppDisabler {
	public function __construct(
		private IAppManager $appManager,
		private AuditLogger $auditLogger,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Disables the app when it is enabled, with an audit row naming the serial.
	 *
	 * @return bool Whether the app was disabled.
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function disable(string $appId, string $serial): bool {
		if (!$this->appManager->isEnabledForAnyone($appId)) {
			return false;
		}

		try {
			$version = $this->appManager->getAppVersion($appId);
			$this->appManager->disableApp($appId);
		} catch (Throwable $error) {
			$this->logger->warning('RevokedAppDisabler: could not disable an app with a revoked certificate', ['appId' => $appId, 'message' => $error->getMessage()]);

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
