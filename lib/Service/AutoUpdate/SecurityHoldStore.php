<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Why the last automatic update run installed no security fix for an app
 * that is affected or on the `security` level (autoupdate-security-first D1):
 * a map of app id to reason, replaced by every run, so "no install" is never
 * silent on the Automatic updates overview.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-the-security-level-installs-only-advisory-fixes-in-the-window
 */
class SecurityHoldStore {
	public const CONFIG_KEY = 'auto_update_security_holds';

	/** The patch policy sets the advisory's severity to by hand. */
	public const REASON_MANUAL = 'manual';

	private const JSON_DEPTH = 4;

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * The reasons recorded by the last run, by app id.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-the-security-level-installs-only-advisory-fixes-in-the-window
	 */
	public function read(): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		try {
			$decoded = $raw === '' ? [] : json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$decoded = [];
		}
		if (!is_array($decoded)) {
			return [];
		}

		$holds = [];
		/** @var mixed $reason */
		foreach ($decoded as $appId => $reason) {
			if (is_string($appId) && is_string($reason)) {
				$holds[$appId] = $reason;
			}
		}

		return $holds;
	}

	/**
	 * Replaces the recorded reasons with this run's.
	 *
	 * @param array<string, string> $holds Reason by app id.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-the-security-level-installs-only-advisory-fixes-in-the-window
	 */
	public function replace(array $holds): void {
		ksort($holds);
		$this->config->setValueString(Application::APP_ID, self::CONFIG_KEY, json_encode($holds, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));
	}
}
