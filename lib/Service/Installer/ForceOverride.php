<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

use InvalidArgumentException;

/**
 * Versioniq's record of one `app_install_overwrite` override it set: the
 * version it forced, the server major it was forced on, who did it and when,
 * and whether Versioniq added the entry (false when the app was already on
 * the list, so the entry is never Versioniq's to remove). Persisted as JSON
 * under app config key `force_incompatible.{appId}`.
 *
 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
 */
final class ForceOverride {
	public function __construct(
		public readonly string $version,
		public readonly int $serverMajor,
		public readonly string $setBy,
		public readonly string $setAt,
		public readonly bool $addedByVersioniq,
	) {
		if ($serverMajor < 1) {
			throw new InvalidArgumentException('A force override needs the server major it was set on.');
		}
	}

	/**
	 * @return array{version: string, serverMajor: int, setBy: string, setAt: string, addedByVersioniq: bool}
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public function toArray(): array {
		return [
			'version' => $this->version,
			'serverMajor' => $this->serverMajor,
			'setBy' => $this->setBy,
			'setAt' => $this->setAt,
			'addedByVersioniq' => $this->addedByVersioniq,
		];
	}

	/**
	 * @param array<array-key, mixed> $payload
	 * @throws InvalidArgumentException When a field is missing or has the wrong type.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public static function fromArray(array $payload): self {
		$version = $payload['version'] ?? null;
		$serverMajor = $payload['serverMajor'] ?? null;
		$setBy = $payload['setBy'] ?? null;
		$setAt = $payload['setAt'] ?? null;
		$added = $payload['addedByVersioniq'] ?? null;
		if (!is_string($version) || !is_int($serverMajor) || !is_string($setBy) || !is_string($setAt) || !is_bool($added)) {
			throw new InvalidArgumentException('Malformed force override record.');
		}

		return new self($version, $serverMajor, $setBy, $setAt, $added);
	}
}
