<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Advisory;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Administrator-settable behaviour for advisory checking: how often the sweep
 * runs, and whether the weekly digest is sent.
 *
 * The interval is CLAMPED rather than validated-and-rejected. A stored value
 * outside the supported range — from a hand-edited `occ config:app:set`, or a
 * future version narrowing the range — must still produce a working schedule;
 * refusing to run because a number is out of bounds would silently stop
 * security checks altogether, which is a worse outcome than checking at a
 * neighbouring frequency.
 *
 * @psalm-api
 */
class AdvisorySettingsStore {
	public const CONFIG_INTERVAL_HOURS = 'advisory.interval_hours';
	public const CONFIG_DIGEST_ENABLED = 'advisory.digest_enabled';
	public const CONFIG_LIBRARIES_ENABLED = 'advisory.libraries_enabled';

	/** Four sweeps a day: well inside the window in which an advisory matters. */
	public const DEFAULT_INTERVAL_HOURS = 6;

	/**
	 * Hourly is the floor because the sweep pulls the App Store catalogue and
	 * the GHSA feed; more often than that is sustained upstream traffic for no
	 * practical gain.
	 */
	public const MIN_INTERVAL_HOURS = 1;

	/**
	 * Daily is the ceiling. Beyond it, "your instance is checked for known
	 * vulnerabilities" stops being a claim the feature can honestly make.
	 */
	public const MAX_INTERVAL_HOURS = 24;

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * The configured sweep interval in hours, clamped to the supported range.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function getIntervalHours(): int {
		$stored = $this->config->getValueInt(
			Application::APP_ID,
			self::CONFIG_INTERVAL_HOURS,
			self::DEFAULT_INTERVAL_HOURS,
		);

		return max(self::MIN_INTERVAL_HOURS, min(self::MAX_INTERVAL_HOURS, $stored));
	}

	/**
	 * The sweep interval in seconds, for scheduling.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function getIntervalSeconds(): int {
		return $this->getIntervalHours() * 3600;
	}

	/**
	 * Stores the sweep interval. Out-of-range values are clamped, not
	 * rejected, so the stored value always describes what actually happens.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function setIntervalHours(int $hours): void {
		$clamped = max(self::MIN_INTERVAL_HOURS, min(self::MAX_INTERVAL_HOURS, $hours));
		$this->config->setValueInt(Application::APP_ID, self::CONFIG_INTERVAL_HOURS, $clamped);
	}

	/**
	 * Whether the weekly digest of non-urgent advisories is sent.
	 *
	 * Defaults to ON. The urgent path (an installed version actually in an
	 * affected range) notifies immediately regardless; the digest is what
	 * carries everything else, and defaulting it off would make that material
	 * invisible unless an admin went looking for a setting they do not know
	 * exists.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function isDigestEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::CONFIG_DIGEST_ENABLED, true);
	}

	/**
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function setDigestEnabled(bool $enabled): void {
		$this->config->setValueBool(Application::APP_ID, self::CONFIG_DIGEST_ENABLED, $enabled);
	}

	/**
	 * Whether the libraries bundled in apps are checked against OSV.
	 *
	 * Defaults to OFF: the check sends library names and versions to an
	 * outside service, which an admin decides to allow.
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 */
	public function isLibraryCheckEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::CONFIG_LIBRARIES_ENABLED, false);
	}

	/**
	 * @spec openspec/changes/advisories-bundled-libraries
	 */
	public function setLibraryCheckEnabled(bool $enabled): void {
		$this->config->setValueBool(Application::APP_ID, self::CONFIG_LIBRARIES_ENABLED, $enabled);
	}
}
