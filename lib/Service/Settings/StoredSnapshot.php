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
	 * Encodes the snapshot and writes it, then the time it was taken. Nothing
	 * is written when the snapshot cannot be encoded, so the previous one stays.
	 *
	 * @param array<array-key, mixed> $map
	 * @throws \JsonException when the snapshot cannot be encoded
	 */
	public function write(array $map, int $checkedAt): void {
		$encoded = json_encode($map, JSON_THROW_ON_ERROR);
		$this->config->setValueString(Application::APP_ID, $this->key, $encoded);
		$this->config->setValueInt(Application::APP_ID, $this->checkedAtKey(), $checkedAt);
	}

	/**
	 * The stored snapshot as the map it was written from, or null when nothing
	 * is stored or the stored JSON is not an array.
	 *
	 * json_decode yields array-key keys; the keys the stores write are app ids.
	 *
	 * @return array<string, mixed>|null
	 * @throws \JsonException when the stored value is not valid JSON
	 */
	public function readMap(): ?array {
		$raw = $this->config->getValueString(Application::APP_ID, $this->key, '');
		if ($raw === '') {
			return null;
		}

		/** @var mixed $decoded */
		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($decoded)) {
			return null;
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * The unix time of the stored sweep, null when none is recorded.
	 */
	public function checkedAt(): ?int {
		$checkedAt = $this->config->getValueInt(Application::APP_ID, $this->checkedAtKey(), 0);

		return $checkedAt > 0 ? $checkedAt : null;
	}

	/**
	 * Records the outcome of a sweep: `ok`, `partial` or `failed`, and the ids
	 * of the apps it could not check.
	 *
	 * @param list<string> $errors
	 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function writeOutcome(string $status, array $errors, ?int $checkedAt = null): void {
		$this->config->setValueString(Application::APP_ID, $this->key . '.status', $status);
		$this->config->setValueString(Application::APP_ID, $this->key . '.errors', json_encode(array_values($errors)) ?: '[]');
		if ($checkedAt !== null) {
			// A sweep that failed as a whole wrote no snapshot: its time is the attempt's.
			$this->config->setValueInt(Application::APP_ID, $this->checkedAtKey(), $checkedAt);
		}
	}

	/**
	 * The outcome of the last sweep, null when none is recorded.
	 */
	public function status(): ?string {
		$status = $this->config->getValueString(Application::APP_ID, $this->key . '.status', '');

		return $status === '' ? null : $status;
	}

	/**
	 * The apps the last sweep could not check.
	 *
	 * @return list<string>
	 */
	public function errors(): array {
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, $this->key . '.errors', '[]'), true);
		if (!is_array($decoded)) {
			return [];
		}

		return array_values(array_map('strval', array_filter($decoded, 'is_scalar')));
	}

	private function checkedAtKey(): string {
		return $this->key . '.checkedAt';
	}
}
