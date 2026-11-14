<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Lifecycle;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * The life cycle signals of one app, read from the version listing the
 * availability sweep already fetched: removed from the App Store, and no
 * release for a year.
 *
 * A signal that cannot be told is null, never false: an app without release
 * dates is "unknown", not "maintained".
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
 */
class LifecycleSignals {
	/** Months without a release before an app counts as stale. */
	public const KEY_STALE_MONTHS = 'lifecycle.stale_months';

	public const DEFAULT_STALE_MONTHS = 12;

	public function __construct(
		private IAppConfig $config,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * The signals of one app from its listing.
	 *
	 * The App Store miss arrives here only for an app that is not shipped:
	 * InstallerService rewrites the miss for a shipped app, which the store
	 * never lists.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
	 * @param string|null $error The listing's error, if any.
	 * @param list<array<string, mixed>> $versions The listed versions.
	 * @return array{removedFromStore: bool, noReleaseFor12Months: ?bool, lastReleasedAt: ?string}
	 */
	public function signals(?string $error, array $versions): array {
		$lastReleasedAt = self::lastReleasedAt($versions);

		return [
			'removedFromStore' => $error === AppStoreSource::NOT_IN_STORE,
			'noReleaseFor12Months' => $lastReleasedAt === null ? null : $lastReleasedAt < $this->staleBefore(),
			'lastReleasedAt' => $lastReleasedAt,
		];
	}

	/**
	 * The newest `releasedAt` among the versions, or null when none has one.
	 *
	 * @param list<array<string, mixed>> $versions
	 */
	private static function lastReleasedAt(array $versions): ?string {
		$last = null;
		foreach ($versions as $version) {
			$released = $version['releasedAt'] ?? null;
			if (is_string($released) && $released !== '' && ($last === null || $released > $last)) {
				$last = $released;
			}
		}

		return $last;
	}

	/**
	 * The instant (in `releasedAt` form) before which a newest release is stale.
	 */
	private function staleBefore(): string {
		$months = $this->config->getValueInt(Application::APP_ID, self::KEY_STALE_MONTHS, self::DEFAULT_STALE_MONTHS);
		if ($months < 1) {
			$months = self::DEFAULT_STALE_MONTHS;
		}

		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))
			->modify('-' . $months . ' months')
			->format('Y-m-d\TH:i:s\Z');
	}
}
