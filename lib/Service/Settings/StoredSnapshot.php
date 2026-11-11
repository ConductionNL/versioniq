<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Settings;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * One background sweep's result in app config: the JSON-encoded snapshot
 * under `$key` and the unix time of the sweep under `$key.checkedAt`. A
 * time of 0 (the key is absent) reads as null, so "never checked" is one
 * value rather than two. Built by the store that owns the keys.
 */
final class StoredSnapshot {
	public function __construct(
		private readonly IAppConfig $config,
		private readonly string $key,
	) {
	}

	/**
	 * Writes the encoded snapshot, then the time it was taken.
	 */
	public function write(string $encoded, int $checkedAt): void {
		$this->config->setValueString(Application::APP_ID, $this->key, $encoded);
		$this->config->setValueInt(Application::APP_ID, $this->checkedAtKey(), $checkedAt);
	}

	/**
	 * The stored JSON, or '' when nothing is stored.
	 */
	public function raw(): string {
		return $this->config->getValueString(Application::APP_ID, $this->key, '');
	}

	/**
	 * The unix time of the stored sweep, null when none is recorded.
	 */
	public function checkedAt(): ?int {
		$checkedAt = $this->config->getValueInt(Application::APP_ID, $this->checkedAtKey(), 0);

		return $checkedAt > 0 ? $checkedAt : null;
	}

	/**
	 * Decodes a stored snapshot to the map it was written from, or null when
	 * the JSON is valid but not an array.
	 *
	 * json_decode yields array-key keys; the keys the stores write are app ids.
	 *
	 * @return array<string, mixed>|null
	 * @throws \JsonException when the stored value is not valid JSON
	 */
	public static function decodeMap(string $raw): ?array {
		/** @var mixed $decoded */
		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($decoded)) {
			return null;
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	private function checkedAtKey(): string {
		return $this->key . '.checkedAt';
	}
}
