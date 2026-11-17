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

	/** Most updates one run may install; absent means no cap (autoupdate-run-batching D4). */
	public const CONFIG_MAX_PER_RUN = 'auto_update.max_per_run';

	/** Most install attempts in seven days; absent means no cap (D4). */
	public const CONFIG_MAX_PER_WEEK = 'auto_update.max_per_week';

	/** The units a cap held back, as `{unitKey: firstHeldAt}` (D5). */
	public const CONFIG_DEFERRED = 'auto_update.deferred';

	/** The highest per-run cap an admin may set. */
	public const MAX_PER_RUN_LIMIT = 50;

	/** The highest weekly cap an admin may set. */
	public const MAX_PER_WEEK_LIMIT = 200;

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
	 * The per-run cap, or null when there is none.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function getMaxPerRun(): ?int {
		return $this->readCap(self::CONFIG_MAX_PER_RUN, self::MAX_PER_RUN_LIMIT);
	}

	/**
	 * The weekly cap, or null when there is none.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function getMaxPerWeek(): ?int {
		return $this->readCap(self::CONFIG_MAX_PER_WEEK, self::MAX_PER_WEEK_LIMIT);
	}

	/**
	 * Set or clear the per-run cap; null clears it.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function setMaxPerRun(?int $max): void {
		$this->writeCap(self::CONFIG_MAX_PER_RUN, $max);
	}

	/**
	 * Set or clear the weekly cap; null clears it.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function setMaxPerWeek(?int $max): void {
		$this->writeCap(self::CONFIG_MAX_PER_WEEK, $max);
	}

	/**
	 * The units a cap held back, keyed by unit, with when each was first held.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function getDeferred(): array {
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, self::CONFIG_DEFERRED, ''), true);
		if (!is_array($decoded)) {
			return [];
		}

		/** @var array<string, string> */
		return array_filter(
			$decoded,
			static fn (mixed $heldAt, int|string $key): bool => is_string($key) && is_string($heldAt) && $heldAt !== '',
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Store the units a cap held back; an empty list removes the key.
	 *
	 * @param array<string, string> $deferred
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function setDeferred(array $deferred): void {
		if ($deferred === []) {
			$this->config->deleteKey(Application::APP_ID, self::CONFIG_DEFERRED);
			return;
		}

		$this->config->setValueString(Application::APP_ID, self::CONFIG_DEFERRED, (string)json_encode($deferred));
	}

	/**
	 * A stored cap, or null when absent or outside 1 to $limit.
	 */
	private function readCap(string $key, int $limit): ?int {
		$value = $this->config->getValueInt(Application::APP_ID, $key, 0);

		return ($value >= 1 && $value <= $limit) ? $value : null;
	}

	/**
	 * Store a cap, or remove it for null.
	 */
	private function writeCap(string $key, ?int $max): void {
		if ($max === null) {
			$this->config->deleteKey(Application::APP_ID, $key);
			return;
		}

		$this->config->setValueInt(Application::APP_ID, $key, $max);
	}
}
