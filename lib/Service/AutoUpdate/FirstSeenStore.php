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
 * When Versioniq first saw each version of an app that came without a release
 * date (releases-minimum-age D2): JSON under `first_seen.{appId}`, version to
 * ISO 8601 UTC, written once and pruned to the versions the source still lists.
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 */
class FirstSeenStore {
	public const KEY_PREFIX = 'first_seen.';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * When the version was first seen, recording now when it never was.
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public function firstSeen(string $appId, string $version, \DateTimeImmutable $now): \DateTimeImmutable {
		$seen = $this->all($appId);
		if (isset($seen[$version])) {
			$parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $seen[$version]);
			if ($parsed !== false) {
				return $parsed;
			}
		}

		$stamp = $now->setTimezone(new \DateTimeZone('UTC'));
		$seen[$version] = $stamp->format(\DateTimeInterface::ATOM);
		$this->write($appId, $seen);

		return $stamp;
	}

	/**
	 * Forget the versions the source no longer lists.
	 *
	 * @param list<string> $listedVersions The versions the source lists now.
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public function prune(string $appId, array $listedVersions): void {
		$seen = $this->all($appId);
		$kept = array_intersect_key($seen, array_flip($listedVersions));
		if ($kept !== $seen) {
			$this->write($appId, $kept);
		}
	}

	/**
	 * Every recorded first-seen time of an app.
	 *
	 * @return array<string, string> Version to ISO 8601.
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public function all(string $appId): array {
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, self::KEY_PREFIX . $appId, ''), true);
		if (!is_array($decoded)) {
			return [];
		}

		return array_filter($decoded, static fn ($value, $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);
	}

	/**
	 * Store the map, or remove the key when it is empty.
	 *
	 * @param array<string, string> $seen
	 */
	private function write(string $appId, array $seen): void {
		if ($seen === []) {
			$this->config->deleteKey(Application::APP_ID, self::KEY_PREFIX . $appId);
			return;
		}

		$this->config->setValueString(Application::APP_ID, self::KEY_PREFIX . $appId, (string)json_encode($seen));
	}
}
