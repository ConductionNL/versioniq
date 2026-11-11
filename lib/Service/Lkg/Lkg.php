<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Lkg;

use InvalidArgumentException;

/**
 * Immutable representation of the last-known-good version record: the last
 * version of an app that finalized cleanly through Versioniq's own install
 * path. Persisted as a JSON blob under app config key `lkg.{appId}`,
 * mirroring the {@see \OCA\Versioniq\Service\Pin\Pin} /
 * {@see \OCA\Versioniq\Service\Source\SourceBinding} pattern; see
 * "Last-known-good version record".
 *
 * @spec openspec/specs/migration-safety/spec.md
 */
final class Lkg {
	private const VERSION_PATTERN = '/^[0-9A-Za-z.\-+]+$/D';

	public function __construct(
		public readonly string $version,
		public readonly string $recordedAt,
		public readonly ?string $sourceId = null,
	) {
		if ($version === '' || preg_match(self::VERSION_PATTERN, $version) !== 1) {
			throw new InvalidArgumentException('Lkg version is empty or contains invalid characters');
		}
		if ($recordedAt === '') {
			throw new InvalidArgumentException('Lkg requires a non-empty recordedAt');
		}
	}

	/**
	 * Serializes the record to its persisted JSON shape; see
	 * "Last-known-good version record".
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 * @return array{version: string, recordedAt: string, sourceId: ?string}
	 */
	public function toArray(): array {
		return [
			'version' => $this->version,
			'recordedAt' => $this->recordedAt,
			'sourceId' => $this->sourceId,
		];
	}

	/**
	 * Reconstructs a record from its persisted payload; see
	 * "Last-known-good version record".
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 * @param array<array-key, mixed> $payload
	 */
	public static function fromArray(array $payload): self {
		$version = $payload['version'] ?? null;
		$recordedAt = $payload['recordedAt'] ?? null;
		if (!is_string($version) || !is_string($recordedAt)) {
			throw new InvalidArgumentException('Lkg payload missing required string fields');
		}

		$sourceId = isset($payload['sourceId']) && is_string($payload['sourceId']) && $payload['sourceId'] !== ''
			? $payload['sourceId']
			: null;

		return new self($version, $recordedAt, $sourceId);
	}
}
