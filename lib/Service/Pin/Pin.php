<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Pin;

use InvalidArgumentException;

/**
 * Immutable representation of a pin: "Versioniq must hold this app at this
 * version". Persisted as a JSON blob under app config key `pin.{appId}`,
 * mirroring the {@see \OCA\Versioniq\Service\Source\SourceBinding} pattern.
 *
 * `driftedTo`/`driftedAt` are set once something other than Versioniq's
 * own install path changes the installed version; they make drift state
 * durable and idempotent (notify once per drifted version, not on every
 * reconcile run). Both are null on a pin with no known drift.
 */
final class Pin {
	private const VERSION_PATTERN = '/^[0-9A-Za-z.\-+]+$/';

	public function __construct(
		public readonly string $version,
		public readonly string $pinnedBy,
		public readonly string $pinnedAt,
		public readonly ?string $reason = null,
		public readonly ?string $driftedTo = null,
		public readonly ?string $driftedAt = null,
	) {
		$this->assertValid();
	}

	/**
	 * @throws InvalidArgumentException when a field is empty or malformed
	 */
	private function assertValid(): void {
		if (!self::isVersion($this->version)) {
			throw new InvalidArgumentException('Pin version is empty or contains invalid characters');
		}
		if ($this->pinnedBy === '') {
			throw new InvalidArgumentException('Pin requires a non-empty pinnedBy');
		}
		if ($this->pinnedAt === '') {
			throw new InvalidArgumentException('Pin requires a non-empty pinnedAt');
		}
		if ($this->driftedTo !== null && !self::isVersion($this->driftedTo)) {
			throw new InvalidArgumentException('Pin driftedTo is empty or contains invalid characters');
		}
		// Drift markers are set together (see "Drift detection") — never one without the other.
		if (($this->driftedTo === null) !== ($this->driftedAt === null)) {
			throw new InvalidArgumentException('Pin driftedTo and driftedAt must both be set or both be null');
		}
	}

	private static function isVersion(string $version): bool {
		return $version !== '' && preg_match(self::VERSION_PATTERN, $version) === 1;
	}

	/**
	 * True while the pin has an unresolved drift; see "Drift detection".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function hasDrifted(): bool {
		return $this->driftedTo !== null;
	}

	/**
	 * Returns a copy with drift markers recorded, all other fields unchanged;
	 * see "Drift detection".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function withDrift(string $driftedTo, string $driftedAt): self {
		return new self($this->version, $this->pinnedBy, $this->pinnedAt, $this->reason, $driftedTo, $driftedAt);
	}

	/**
	 * Returns a copy with drift markers cleared, all other fields unchanged;
	 * see "Re-pin reinstalls the pinned version" and "Accept the new version".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function withoutDrift(): self {
		if (!$this->hasDrifted()) {
			return $this;
		}

		return new self($this->version, $this->pinnedBy, $this->pinnedAt, $this->reason, null, null);
	}

	/**
	 * Serializes the pin to its persisted JSON shape; see "Pin an installed app to its current version".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$payload = [
			'version' => $this->version,
			'pinnedBy' => $this->pinnedBy,
			'pinnedAt' => $this->pinnedAt,
		];
		if ($this->reason !== null) {
			$payload['reason'] = $this->reason;
		}
		if ($this->driftedTo !== null) {
			$payload['driftedTo'] = $this->driftedTo;
			$payload['driftedAt'] = $this->driftedAt;
		}

		return $payload;
	}

	/**
	 * Reconstructs a pin from its persisted payload; see "Pin an installed app to its current version".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @param array<array-key, mixed> $payload
	 */
	public static function fromArray(array $payload): self {
		$version = $payload['version'] ?? null;
		$pinnedBy = $payload['pinnedBy'] ?? null;
		$pinnedAt = $payload['pinnedAt'] ?? null;
		if (!is_string($version) || !is_string($pinnedBy) || !is_string($pinnedAt)) {
			throw new InvalidArgumentException('Pin payload missing required string fields');
		}

		return new self(
			$version,
			$pinnedBy,
			$pinnedAt,
			self::optionalString($payload, 'reason'),
			self::optionalString($payload, 'driftedTo'),
			self::optionalString($payload, 'driftedAt'),
		);
	}

	/**
	 * The non-empty string under $key, or null.
	 *
	 * @param array<array-key, mixed> $payload
	 */
	private static function optionalString(array $payload, string $key): ?string {
		return isset($payload[$key]) && is_string($payload[$key]) && $payload[$key] !== '' ? $payload[$key] : null;
	}
}
