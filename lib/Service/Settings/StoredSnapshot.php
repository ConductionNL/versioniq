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
	 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function writeOutcome(string $status, array $errors, ?int $failedAt = null): void {
		$this->config->setValueString(Application::APP_ID, $this->key . '.status', $status);
		$encoded = json_encode($errors);
		$this->config->setValueString(Application::APP_ID, $this->key . '.errors', is_string($encoded) ? $encoded : '[]');
		// A sweep that failed as a whole wrote no snapshot: its attempt time is kept
		// apart, so `checkedAt` stays the time of the last sweep that succeeded.
		$this->config->setValueInt(Application::APP_ID, $this->key . '.failedAt', $failedAt ?? 0);
	}

	/**
	 * The unix time of the last sweep that failed as a whole, null when the last
	 * recorded outcome was not such a failure.
	 */
	public function failedAt(): ?int {
		$failedAt = $this->config->getValueInt(Application::APP_ID, $this->key . '.failedAt', 0);

		return $failedAt > 0 ? $failedAt : null;
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

	/**
	 * Writes a completed sweep: the snapshot, its time, and the outcome its
	 * entries give. Nothing is written when the snapshot cannot be encoded.
	 *
	 * @param array<array-key, mixed> $map
	 * @throws \JsonException when the snapshot cannot be encoded
	 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function writeSweep(array $map, int $checkedAt): void {
		$this->write($map, $checkedAt);
		$outcome = SweepOutcome::of($map);
		$this->writeOutcome($outcome['status'], $outcome['errors']);
	}

	/**
	 * The time of the last sweep that succeeded and the outcome of the last
	 * sweep. `checkedAt` is never a failed attempt's time: readers that show it
	 * without the status (occ, MCP) must not read a failure as freshly checked.
	 * A sweep that failed as a whole has its time in `failedAt`.
	 *
	 * A snapshot stored before outcomes were recorded gets the outcome its own
	 * entries give.
	 *
	 * @param array<string, mixed>|null $map The stored snapshot, null when there is none.
	 * @return array{checkedAt: ?int, failedAt: ?int, status: ?string, errors: list<string>}
	 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function outcome(?array $map): array {
		$status = $this->status();
		if ($map === null) {
			return $this->outcomeWithoutSnapshot($status);
		}
		if ($status === null) {
			return ['checkedAt' => $this->checkedAt(), 'failedAt' => null] + SweepOutcome::of($map);
		}

		return $this->recordedOutcome($status, $this->checkedAt());
	}

	/**
	 * The outcome when no snapshot is stored: only a recorded whole-sweep
	 * failure says anything.
	 *
	 * @return array{checkedAt: ?int, failedAt: ?int, status: ?string, errors: list<string>}
	 */
	private function outcomeWithoutSnapshot(?string $status): array {
		if ($status !== SweepOutcome::FAILED) {
			return ['checkedAt' => null, 'failedAt' => null, 'status' => null, 'errors' => []];
		}

		return $this->recordedOutcome($status, null);
	}

	/**
	 * @return array{checkedAt: ?int, failedAt: ?int, status: string, errors: list<string>}
	 */
	private function recordedOutcome(string $status, ?int $checkedAt): array {
		return [
			'checkedAt' => $checkedAt,
			'failedAt' => $status === SweepOutcome::FAILED ? $this->failedAt() : null,
			'status' => $status,
			'errors' => $this->errors(),
		];
	}

	private function checkedAtKey(): string {
		return $this->key . '.checkedAt';
	}
}
