<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Source;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The server and PHP range read for a forge release, kept per source and
 * version so each release tag is read from the forge once
 * (inventory-upgrade-readiness D2). A release whose `info.xml` could not be
 * read is kept with no bounds, so it is not asked for again either.
 *
 * The key is `forge_range.` and a digest of the source id and version: a
 * source id plus a version can be longer than an app config key may be.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
 * @psalm-api
 */
class ForgeRangeStore {
	private const KEY_PREFIX = 'forge_range.';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * The stored ranges of a release ({@see release()}), or null when it was never read.
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
	 */
	public function find(string $release): ?InfoXmlRanges {
		$raw = $this->config->getValueString(Application::APP_ID, self::key($release), '');
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? InfoXmlRanges::fromArray($decoded) : null;
	}

	/**
	 * Keep what was read for a release; no bounds means it declares none.
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
	 */
	public function save(string $release, InfoXmlRanges $ranges): void {
		$this->config->setValueString(Application::APP_ID, self::key($release), json_encode($ranges->toArray(), JSON_THROW_ON_ERROR));
	}

	/**
	 * The name a release is stored under: its source id and version.
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
	 */
	public static function release(SourceBinding $binding, string $version): string {
		return $binding->getId() . "\n" . $version;
	}

	private static function key(string $release): string {
		return self::KEY_PREFIX . substr(hash('sha256', $release), 0, 40);
	}
}
