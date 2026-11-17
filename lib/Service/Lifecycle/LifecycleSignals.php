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
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceLookup;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * The life cycle signals of one app, read from the version listing the
 * availability sweep already fetched: removed from the App Store, and no
 * release for a year; whether the forge repository is archived, which
 * takes one request per forge app; and whether any listed release still
 * supports a maintained server major, read from the end-of-life feed and the
 * cached App Store catalogue.
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

	/** Majors above the newest one the feed lists that are tested too: a release for them is for a server still maintained. */
	private const MAJORS_AHEAD = 3;

	public function __construct(
		private IAppConfig $config,
		private ITimeFactory $timeFactory,
		private ?SourceLookup $sources = null,
		private ?ServerEolFeed $eolFeed = null,
	) {
	}

	/**
	 * Whether the app is out of support: no listed release supports a server
	 * major that is still maintained (design D2). Null when that cannot be
	 * told: the feed is off or unreadable, the app is not bound to the App
	 * Store, or its releases name no server range. A forge release publishes
	 * no range, so a forge app reads unknown, never "out of support".
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
	 */
	public function endOfSupport(string $appId): ?bool {
		$known = array_keys($this->eolFeed?->majors() ?? []);
		if ($known === []) {
			return null;
		}

		$binding = $this->sources?->bindingStore->get($appId);
		if ($binding === null) {
			return null;
		}
		try {
			$source = $this->sources?->sourceRegistry->get($binding);
		} catch (\Throwable) {
			return null;
		}
		if (!$source instanceof AppStoreSource) {
			return null;
		}

		$newest = max($known);
		$supported = $source->supportedMajors($appId, array_merge($known, range($newest + 1, $newest + self::MAJORS_AHEAD)));
		if ($supported === null) {
			return null;
		}
		if ($supported !== [] && max($supported) > $newest) {
			// A release for a major newer than any the feed lists is for a maintained server.
			return false;
		}

		return $this->eolFeed?->endOfSupport($supported);
	}

	/**
	 * Whether the app's forge repository is archived: null for an app not
	 * bound to a forge, and when the forge could not be asked.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
	 */
	public function archived(string $appId): ?bool {
		$binding = $this->sources?->bindingStore->get($appId);
		if ($binding === null) {
			return null;
		}
		try {
			$source = $this->sources?->sourceRegistry->get($binding);
		} catch (\Throwable) {
			return null;
		}

		return $source instanceof ForgeReleaseSource ? $source->isArchived($binding) : null;
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
			if (is_string($released) && $released !== '' && $released > (string)$last) {
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
