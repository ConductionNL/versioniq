<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Availability;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Remembers the versions an app's last successful listing contained, and the
 * versions that an earlier successful listing contained and a later one did
 * not: the versions its publisher withdrew (safety-withdrawn-and-revoked, design D3).
 *
 * Only a SUCCESSFUL listing is recorded, so a source that failed or answered
 * with an error never makes a version look withdrawn. A version that comes
 * back in a later listing is no longer withdrawn. The withdrawn list is kept
 * under `withdrawn.<appId>`, at most {@see MAX_WITHDRAWN} entries, newest last.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-a-withdrawn-installed-version-is-marked-and-never-reinstalled-by-accident
 *
 * @psalm-api
 */
class WithdrawnVersionStore {
	/** The most withdrawn versions kept per app. */
	public const MAX_WITHDRAWN = 50;

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * Record a successful listing and return the versions it newly shows withdrawn.
	 * The first listing of an app only sets the baseline.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-a-withdrawn-installed-version-is-marked-and-never-reinstalled-by-accident
	 * @param list<string> $versions The versions the listing contained.
	 * @return list<string> Versions the previous listing had and this one has not.
	 */
	public function recordListing(string $appId, array $versions): array {
		$current = array_values(array_unique(array_map('strval', $versions)));
		$previous = $this->read('availability.listed.' . $appId);
		$this->write('availability.listed.' . $appId, $current);
		if ($previous === null) {
			return [];
		}

		$gone = array_values(array_diff($previous, $current));
		$withdrawn = array_values(array_diff($this->withdrawnVersions($appId), $current, $gone));
		$withdrawn = array_slice([...$withdrawn, ...$gone], -self::MAX_WITHDRAWN);
		if ($withdrawn !== $this->withdrawnVersions($appId)) {
			$this->write('withdrawn.' . $appId, $withdrawn);
		}

		return $gone;
	}

	/**
	 * The versions recorded as withdrawn for an app, oldest first.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-a-withdrawn-installed-version-is-marked-and-never-reinstalled-by-accident
	 * @return list<string>
	 */
	public function withdrawnVersions(string $appId): array {
		return $this->read('withdrawn.' . $appId) ?? [];
	}

	/**
	 * Whether a version of an app is recorded as withdrawn.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-a-withdrawn-installed-version-is-marked-and-never-reinstalled-by-accident
	 */
	public function isWithdrawn(string $appId, string $version): bool {
		return in_array($version, $this->withdrawnVersions($appId), true);
	}

	/**
	 * A stored list of versions, or null when nothing (readable) is stored.
	 *
	 * @return list<string>|null
	 */
	private function read(string $key): ?array {
		$raw = $this->config->getValueString(Application::APP_ID, $key, '');
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || !array_is_list($decoded)) {
			return null;
		}

		return array_values(array_filter($decoded, 'is_string'));
	}

	/**
	 * Store a list of versions.
	 *
	 * @param list<string> $versions
	 */
	private function write(string $key, array $versions): void {
		$this->config->setValueString(Application::APP_ID, $key, (string)json_encode($versions));
	}
}
