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
 * Which installed apps carry a revoked signing certificate, as the daily
 * recheck found it (safety-withdrawn-and-revoked, design D2): app config
 * `revoked.<appId>` holds the revoked serial number, hex. The Apps tab reads
 * it through `GET /api/apps` (`certificateRevoked`); the serial makes the
 * notification go out once per app and certificate.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 *
 * @psalm-api
 */
class RevokedCertificateStore {
	public const KEY_PREFIX = 'revoked.';

	/** Whether an app with a revoked certificate is disabled automatically; off by default. */
	public const KEY_DISABLE = 'safety.disable_revoked';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * The revoked serial recorded for an app, or null when its certificate is not revoked.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function serialFor(string $appId): ?string {
		$serial = $this->config->getValueString(Application::APP_ID, self::KEY_PREFIX . $appId, '');

		return $serial === '' ? null : $serial;
	}

	/**
	 * Whether the app carries a revoked certificate.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function isRevoked(string $appId): bool {
		return $this->serialFor($appId) !== null;
	}

	/**
	 * Records that the app's certificate with this serial is revoked.
	 *
	 * @return bool Whether this is new: the app was not recorded with this serial yet.
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function mark(string $appId, string $serial): bool {
		if ($this->serialFor($appId) === $serial) {
			return false;
		}

		$this->config->setValueString(Application::APP_ID, self::KEY_PREFIX . $appId, $serial);

		return true;
	}

	/**
	 * Forgets the record: the app's certificate is no longer on the list.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function clear(string $appId): void {
		if ($this->serialFor($appId) !== null) {
			$this->config->deleteKey(Application::APP_ID, self::KEY_PREFIX . $appId);
		}
	}

	/**
	 * Whether an admin switched on disabling apps with a revoked certificate.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function disablesRevoked(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_DISABLE, false);
	}
}
