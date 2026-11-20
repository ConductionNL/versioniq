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
 * `requireApproval` holds every automatic update of the app until an admin
 * approves that version.
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
		// Whether the job installs only a version an admin approved
		// (autoupdate-proposed-updates, design D4). Absent reads as false.
		public readonly bool $requireApproval = false,
		// Whether a manual install outside the level needs an explicit override
		// (pinning-ranges-and-skips). Absent reads as false.
		public readonly bool $holdManual = false,
		// The policy's own minimum age in days; null follows the instance's (releases-minimum-age).
		public readonly ?int $minAgeDays = null,
	) {
		$this->assertRequiredFields();
		$this->assertMinAgeDays();
	}

	/**
	 * Refuses an unknown level or an empty setBy or setAt.
	 *
	 * @throws InvalidArgumentException When a required field is invalid.
	 */
	private function assertRequiredFields(): void {
		if (!self::isValidLevel($this->level)) {
			throw new InvalidArgumentException('Policy level must be one of: ' . implode(', ', self::VALID_LEVELS));
		}
		if ($this->setBy === '') {
			throw new InvalidArgumentException('Policy requires a non-empty setBy');
		}
		if ($this->setAt === '') {
			throw new InvalidArgumentException('Policy requires a non-empty setAt');
		}
	}

	/**
	 * Refuses a minimum age outside 0 to 90 days; absent uses the instance value.
	 *
	 * @throws InvalidArgumentException When minAgeDays is out of range.
	 */
	private function assertMinAgeDays(): void {
		if ($this->minAgeDays !== null && ($this->minAgeDays < 0 || $this->minAgeDays > 90)) {
			throw new InvalidArgumentException('Policy minAgeDays must be 0 to 90, or absent to use the instance value');
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
		$payload = [
			'level' => $this->level,
			'setBy' => $this->setBy,
			'setAt' => $this->setAt,
			'requireApproval' => $this->requireApproval,
			'holdManual' => $this->holdManual,
		];
		if ($this->minAgeDays !== null) {
			$payload['minAgeDays'] = $this->minAgeDays;
		}

		return $payload;
	}

	/**
	 * Whether Versioniq's own installs are held to this policy's level: the
	 * switch is on and the level is `patch` or `minor`.
	 *
	 * @spec openspec/changes/pinning-ranges-and-skips/specs/auto-update-policies/spec.md#requirement-a-policy-can-hold-manual-installs-to-its-level
	 */
	public function holdsManualInstalls(): bool {
		return $this->holdManual && in_array($this->level, [self::LEVEL_PATCH, self::LEVEL_MINOR], true);
	}

	/**
	 * Reconstructs a policy from its persisted payload; see "Per-app update policy".
	 * A payload without `requireApproval` (stored before it existed) reads as false.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 * @param array<array-key, mixed> $payload
	 */
	public static function fromArray(array $payload): self {
		$level = $payload['level'] ?? null;
		$setBy = $payload['setBy'] ?? null;
		$setAt = $payload['setAt'] ?? null;
		if (!is_string($level) || !is_string($setBy) || !is_string($setAt)) {
			throw new InvalidArgumentException('Policy payload missing required string fields');
		}

		// A policy stored before pinning-ranges-and-skips has no holdManual: off.
		return new self($level, $setBy, $setAt, ($payload['requireApproval'] ?? false) === true, ($payload['holdManual'] ?? false) === true, self::minAgeDaysFrom($payload));
	}

	/**
	 * The optional `minAgeDays` of a persisted payload.
	 *
	 * @param array<array-key, mixed> $payload
	 * @throws InvalidArgumentException When it is present and not an integer.
	 */
	private static function minAgeDaysFrom(array $payload): ?int {
		$minAgeDays = $payload['minAgeDays'] ?? null;
		if ($minAgeDays !== null && !is_int($minAgeDays)) {
			throw new InvalidArgumentException('Policy minAgeDays must be an integer');
		}

		return $minAgeDays;
	}
}
