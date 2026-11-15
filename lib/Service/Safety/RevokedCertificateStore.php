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
use OCP\IAppConfig;

/**
 * The installed apps whose signing certificate the daily recheck found
 * revoked, with the revoked serial (design D2 of safety-withdrawn-and-revoked).
 * Kept in app config `safety.revoked.<appId>`; the Apps tab reads it as the
 * card's `certificateRevoked`.
 */
class RevokedCertificateStore {
	/** App config key prefix of the revoked serial of an app. */
	public const KEY_PREFIX = 'safety.revoked.';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * The revoked serial recorded for the app, or null when none is.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/tasks.md#2.1
	 */
	public function get(string $appId): ?string {
		$serial = trim($this->config->getValueString(Application::APP_ID, self::KEY_PREFIX . $appId, ''));

		return $serial === '' ? null : $serial;
	}

	/**
	 * Records the app's revoked serial; true when it was not recorded yet, so
	 * an admin is notified once per app and certificate.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/tasks.md#2.1
	 */
	public function mark(string $appId, string $serial): bool {
		if ($this->get($appId) === $serial) {
			return false;
		}

		$this->config->setValueString(Application::APP_ID, self::KEY_PREFIX . $appId, $serial);

		return true;
	}

	/**
	 * Forgets the record, once the app carries a certificate that is not revoked.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/tasks.md#2.1
	 */
	public function clear(string $appId): void {
		if ($this->get($appId) !== null) {
			$this->config->deleteKey(Application::APP_ID, self::KEY_PREFIX . $appId);
		}
	}
}
