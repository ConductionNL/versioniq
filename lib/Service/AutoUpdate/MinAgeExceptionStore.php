<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The one version per app an admin released before the minimum age
 * (releases-minimum-age D5): `min_age_exception.{appId}` holds
 * `{version, by, at}`; a new exception replaces the old.
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 * @psalm-api
 */
class MinAgeExceptionStore {
	public const KEY_PREFIX = 'min_age_exception.';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * The app's exception, or null when it has none or the stored value is unreadable.
	 *
	 * @return array{version: string, by: string, at: string}|null
	 */
	public function get(string $appId): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_PREFIX . $appId, '');
		if ($raw === '') {
			return null;
		}
		try {
			/** @var mixed $value */
			$value = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($value) || !is_string($value['version'] ?? null) || $value['version'] === '') {
			return null;
		}

		return [
			'version' => $value['version'],
			'by' => is_string($value['by'] ?? null) ? $value['by'] : '',
			'at' => is_string($value['at'] ?? null) ? $value['at'] : '',
		];
	}

	public function set(string $appId, string $version, string $by, \DateTimeImmutable $at): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::KEY_PREFIX . $appId,
			json_encode(['version' => $version, 'by' => $by, 'at' => $at->format(DATE_ATOM)], JSON_THROW_ON_ERROR),
		);
	}

	public function remove(string $appId): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::KEY_PREFIX . $appId);
	}
}
