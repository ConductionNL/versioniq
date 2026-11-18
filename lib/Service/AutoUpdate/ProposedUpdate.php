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

use InvalidArgumentException;

/**
 * The update an app's policy would install at the next due opening of the
 * window, worked out ahead of that window: the step, when it is planned, the
 * target version's release notes, and where it stands (planned, in notice,
 * awaiting approval, approved or declined).
 *
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 *
 * @psalm-api
 */
final class ProposedUpdate {
	public const STATUS_PLANNED = 'planned';
	public const STATUS_IN_NOTICE = 'in_notice';
	public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_DECLINED = 'declined';

	/** @var list<string> */
	public const STATUSES = [
		self::STATUS_PLANNED,
		self::STATUS_IN_NOTICE,
		self::STATUS_AWAITING_APPROVAL,
		self::STATUS_APPROVED,
		self::STATUS_DECLINED,
	];

	/** Release notes are cut to this many characters (design D1). */
	public const RELEASE_NOTES_MAX = 2000;

	/**
	 * @param array{by: string, at: string, reason?: string}|null $decision who approved or declined this version, and when
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $fromVersion,
		public readonly string $toVersion,
		public readonly string $kind,
		public readonly string $status,
		public readonly string $proposedAt,
		public readonly ?string $plannedFor = null,
		public readonly ?string $announcedAt = null,
		public readonly string $releaseNotes = '',
		public readonly ?array $decision = null,
	) {
		if ($appId === '' || $toVersion === '') {
			throw new InvalidArgumentException('A proposed update needs an app id and a target version');
		}
		if (!in_array($status, self::STATUSES, true)) {
			throw new InvalidArgumentException('Proposed update status must be one of: ' . implode(', ', self::STATUSES));
		}
	}

	/**
	 * The release notes cut to {@see RELEASE_NOTES_MAX} characters.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public static function excerpt(string $notes): string {
		$notes = trim($notes);

		return mb_strlen($notes) > self::RELEASE_NOTES_MAX
			? mb_substr($notes, 0, self::RELEASE_NOTES_MAX - 1) . '…'
			: $notes;
	}

	/**
	 * The step between two versions: `major`, `minor` or `patch`, or `other`
	 * when either is not plain major.minor.patch.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public static function kindOf(string $fromVersion, string $toVersion): string {
		$from = self::parts($fromVersion);
		$to = self::parts($toVersion);
		if ($from === null || $to === null) {
			return 'other';
		}
		if ($to[0] !== $from[0]) {
			return 'major';
		}

		return $to[1] !== $from[1] ? 'minor' : 'patch';
	}

	/**
	 * The same proposal with another status, planned time and announcement time.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 * @param array{by: string, at: string, reason?: string}|null $decision
	 */
	public function with(string $status, ?string $plannedFor, ?string $announcedAt, ?array $decision): self {
		return new self(
			$this->appId,
			$this->fromVersion,
			$this->toVersion,
			$this->kind,
			$status,
			$this->proposedAt,
			$plannedFor,
			$announcedAt,
			$this->releaseNotes,
			$decision,
		);
	}

	/**
	 * The stored and returned shape.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$decision = $this->decision;
		$approved = $this->status === self::STATUS_APPROVED ? $decision : null;
		$declined = $this->status === self::STATUS_DECLINED ? $decision : null;

		return [
			'appId' => $this->appId,
			'fromVersion' => $this->fromVersion,
			'toVersion' => $this->toVersion,
			'kind' => $this->kind,
			'status' => $this->status,
			'proposedAt' => $this->proposedAt,
			'plannedFor' => $this->plannedFor,
			'announcedAt' => $this->announcedAt,
			'releaseNotes' => $this->releaseNotes,
			'approvedBy' => $approved['by'] ?? null,
			'approvedAt' => $approved['at'] ?? null,
			'declinedBy' => $declined['by'] ?? null,
			'declinedAt' => $declined['at'] ?? null,
			'declineReason' => $declined['reason'] ?? null,
		];
	}

	/**
	 * Rebuilds a proposal from {@see toArray()}; throws on a payload without
	 * the required fields.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 * @param array<array-key, mixed> $payload
	 */
	public static function fromArray(array $payload): self {
		$required = [];
		foreach (['appId', 'fromVersion', 'toVersion', 'kind', 'status', 'proposedAt'] as $field) {
			$value = self::optional($payload, $field);
			if ($value === null) {
				throw new InvalidArgumentException('Proposed update payload is missing ' . $field);
			}
			$required[$field] = $value;
		}

		return new self(
			$required['appId'],
			$required['fromVersion'],
			$required['toVersion'],
			$required['kind'],
			$required['status'],
			$required['proposedAt'],
			self::optional($payload, 'plannedFor'),
			self::optional($payload, 'announcedAt'),
			self::optional($payload, 'releaseNotes') ?? '',
			self::decisionFrom($payload),
		);
	}

	/**
	 * @param array<array-key, mixed> $payload
	 * @return array{by: string, at: string, reason?: string}|null
	 */
	private static function decisionFrom(array $payload): ?array {
		$by = self::optional($payload, 'approvedBy') ?? self::optional($payload, 'declinedBy');
		$at = self::optional($payload, 'approvedAt') ?? self::optional($payload, 'declinedAt');
		if ($by === null || $at === null) {
			return null;
		}
		$reason = self::optional($payload, 'declineReason');

		return $reason === null ? ['by' => $by, 'at' => $at] : ['by' => $by, 'at' => $at, 'reason' => $reason];
	}

	/**
	 * @param array<array-key, mixed> $payload
	 */
	private static function optional(array $payload, string $field): ?string {
		/** @var mixed $value */
		$value = $payload[$field] ?? null;

		return is_string($value) ? $value : null;
	}

	/**
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	private static function parts(string $version): ?array {
		if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', trim($version), $matches) !== 1) {
			return null;
		}

		return [(int)$matches[1], (int)$matches[2], (int)$matches[3]];
	}
}
