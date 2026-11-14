<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Reads and writes the two global auto-update settings: `auto_update_enabled`
 * (the kill switch, default `false`) and `auto_update_window` (default
 * {@see AutoUpdateWindow::DEFAULT_WINDOW}). Plain app-config values — see
 * design.md "Storage" ("Globals").
 *
 * @psalm-api
 */
class AutoUpdateSettingsStore {
	public const CONFIG_ENABLED = 'auto_update_enabled';
	public const CONFIG_WINDOW = 'auto_update_window';
	/** Opening key ({@see AutoUpdateWindow::openingKey()}) of the last window the job swept. */
	public const CONFIG_LAST_SWEPT_WINDOW = 'auto_update_last_swept_window';
	/** Days a proposed update is announced before it may install; 0 is off. */
	public const CONFIG_NOTICE_DAYS = 'auto_update.notice_days';
	/** A Nextcloud group that hears of planned updates besides the admins. */
	public const CONFIG_NOTICE_GROUP = 'auto_update.notice_group';
	public const MAX_NOTICE_DAYS = 60;

	public function __construct(
		private IAppConfig $config,
		// Optional and last so existing callers keep working; the container
		// always passes it.
		private ?IConfig $systemConfig = null,
	) {
	}

	/**
	 * The time zone the window is read in: Nextcloud's `default_timezone`
	 * system setting, or UTC when it is unset or not a valid zone. PHP runs
	 * Nextcloud in UTC, so "server time" used to mean UTC without saying so
	 * (#429).
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function getTimeZoneName(): string {
		$zone = $this->systemConfig?->getSystemValueString('default_timezone', 'UTC') ?? 'UTC';
		if ($zone === '' || !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
			return 'UTC';
		}

		return $zone;
	}

	/**
	 * Whether the job already swept the window with this opening key.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function hasSweptWindow(string $openingKey): bool {
		return $this->config->getValueString(Application::APP_ID, self::CONFIG_LAST_SWEPT_WINDOW, '') === $openingKey;
	}

	/**
	 * Records that the job swept the window with this opening key.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function markWindowSwept(string $openingKey): void {
		$this->config->setValueString(Application::APP_ID, self::CONFIG_LAST_SWEPT_WINDOW, $openingKey);
	}

	/**
	 * Whether the global auto-update kill switch is on; see "Global kill
	 * switch and window".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function isEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::CONFIG_ENABLED, false);
	}

	/**
	 * The configured maintenance window, falling back to the default when
	 * unset or empty; see "Global kill switch and window".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function getWindow(): string {
		$window = $this->config->getValueString(Application::APP_ID, self::CONFIG_WINDOW, AutoUpdateWindow::DEFAULT_WINDOW);

		return $window === '' ? AutoUpdateWindow::DEFAULT_WINDOW : $window;
	}

	/**
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function setEnabled(bool $enabled): void {
		$this->config->setValueBool(Application::APP_ID, self::CONFIG_ENABLED, $enabled);
	}

	/**
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function setWindow(string $window): void {
		$this->config->setValueString(Application::APP_ID, self::CONFIG_WINDOW, $window);
	}

	/**
	 * The notice period in days (0 when off), clamped to 0..{@see MAX_NOTICE_DAYS}.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function getNoticeDays(): int {
		$days = $this->config->getValueInt(Application::APP_ID, self::CONFIG_NOTICE_DAYS, 0);

		return max(0, min(self::MAX_NOTICE_DAYS, $days));
	}

	/**
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function setNoticeDays(int $days): void {
		$this->config->setValueInt(Application::APP_ID, self::CONFIG_NOTICE_DAYS, $days);
	}

	/**
	 * The group id that is told of planned updates besides the admins; '' when none.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function getNoticeGroup(): string {
		return $this->config->getValueString(Application::APP_ID, self::CONFIG_NOTICE_GROUP, '');
	}

	/**
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function setNoticeGroup(string $groupId): void {
		$this->config->setValueString(Application::APP_ID, self::CONFIG_NOTICE_GROUP, $groupId);
	}
}
