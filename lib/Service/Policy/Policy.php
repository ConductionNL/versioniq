<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Policy;

use InvalidArgumentException;

/**
 * Immutable representation of a per-app auto-update policy: "Versioniq
 * may automatically install newer versions of this app up to this semver
 * level". Persisted as a JSON blob under app config key `policy.{appId}`,
 * mirroring the {@see \OCA\Versioniq\Service\Pin\Pin} pattern.
 *
 * `level` bounds the blast radius of {@see \OCA\Versioniq\BackgroundJob\AutoUpdateJob}:
 * `none` (the default when no policy is stored), `patch` (same major.minor),
 * `minor` (same major), `all` (any newer version), or `security` (only the
 * fix the advisory check recommends for an affected installed version, see
 * {@see \OCA\Versioniq\Service\AutoUpdate\SecurityCandidate}).
 */
final class Policy {
	public const LEVEL_NONE = 'none';
	public const LEVEL_PATCH = 'patch';
	public const LEVEL_MINOR = 'minor';
	public const LEVEL_ALL = 'all';
	public const LEVEL_SECURITY = 'security';

	/** @var list<string> */
	public const VALID_LEVELS = [self::LEVEL_NONE, self::LEVEL_PATCH, self::LEVEL_MINOR, self::LEVEL_ALL, self::LEVEL_SECURITY];

	public function __construct(
		public readonly string $level,
		public readonly string $setBy,
		public readonly string $setAt,
	) {
		if (!self::isValidLevel($level)) {
			throw new InvalidArgumentException('Policy level must be one of: ' . implode(', ', self::VALID_LEVELS));
		}
		if ($setBy === '') {
			throw new InvalidArgumentException('Policy requires a non-empty setBy');
		}
		if ($setAt === '') {
			throw new InvalidArgumentException('Policy requires a non-empty setAt');
		}
	}

	/**
	 * Whether `$level` is one of the accepted policy levels; see "Per-app
	 * update policy" ("Invalid level rejected").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public static function isValidLevel(string $level): bool {
		return in_array($level, self::VALID_LEVELS, true);
	}

	/**
	 * Serializes the policy to its persisted JSON shape; see "Per-app update policy".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'level' => $this->level,
			'setBy' => $this->setBy,
			'setAt' => $this->setAt,
		];
	}

	/**
	 * Reconstructs a policy from its persisted payload; see "Per-app update policy".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @param array<array-key, mixed> $payload
	 */
	public static function fromArray(array $payload): self {
		$level = $payload['level'] ?? null;
		$setBy = $payload['setBy'] ?? null;
		$setAt = $payload['setAt'] ?? null;
		if (!is_string($level) || !is_string($setBy) || !is_string($setAt)) {
			throw new InvalidArgumentException('Policy payload missing required string fields');
		}

		return new self($level, $setBy, $setAt);
	}
}
