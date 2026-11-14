<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Policy;

use InvalidArgumentException;
use OCA\Versioniq\Service\Advisory\AdvisorySeverity;

/**
 * The instance's written patch policy (autoupdate-security-first D3): per
 * advisory severity a deadline and how its fix is handled, per regular kind
 * of update whether it installs automatically, and a free text statement.
 *
 * {@see self::none()} is the reading with nothing stored: every severity
 * `scheduled` without a deadline and every kind `automatic`, which is exactly
 * how the nightly job behaved before the patch policy existed.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
 */
final class PatchPolicy {
	public const SEVERITIES = [
		AdvisorySeverity::CRITICAL,
		AdvisorySeverity::HIGH,
		AdvisorySeverity::MEDIUM,
		AdvisorySeverity::LOW,
		AdvisorySeverity::UNKNOWN,
	];

	public const HANDLING_NEXT_WINDOW = 'next_window';
	public const HANDLING_SCHEDULED = 'scheduled';
	public const HANDLING_MANUAL = 'manual';

	public const SECURITY_HANDLINGS = [self::HANDLING_NEXT_WINDOW, self::HANDLING_SCHEDULED, self::HANDLING_MANUAL];

	public const KIND_PATCH = 'patch';
	public const KIND_MINOR = 'minor';
	public const KIND_MAJOR = 'major';

	public const KINDS = [self::KIND_PATCH, self::KIND_MINOR, self::KIND_MAJOR];

	public const HANDLING_AUTOMATIC = 'automatic';

	public const REGULAR_HANDLINGS = [self::HANDLING_AUTOMATIC, self::HANDLING_MANUAL];

	public const DEADLINE_MIN_DAYS = 1;
	public const DEADLINE_MAX_DAYS = 365;
	public const STATEMENT_MAX_LENGTH = 4000;

	/**
	 * @param array<string, array{deadlineDays: ?int, handling: string}> $security By severity, every severity present.
	 * @param array<string, string> $regular By kind, every kind present.
	 */
	private function __construct(
		public readonly array $security,
		public readonly array $regular,
		public readonly string $statement,
		public readonly ?string $setBy,
		public readonly ?string $setAt,
	) {
	}

	/**
	 * The policy read when none is stored: today's behaviour.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public static function none(): self {
		$security = [];
		foreach (self::SEVERITIES as $severity) {
			$security[$severity] = ['deadlineDays' => null, 'handling' => self::HANDLING_SCHEDULED];
		}

		return new self($security, array_fill_keys(self::KINDS, self::HANDLING_AUTOMATIC), '', null, null);
	}

	/**
	 * Builds a policy from a stored record or a request. A severity or kind
	 * that is left out keeps the no-policy reading; one that is present must
	 * be valid. Validates everything before returning, so nothing partial is
	 * ever built.
	 *
	 * @param array<array-key, mixed> $payload The `security`, `regular` and `statement` fields.
	 *
	 * @throws InvalidArgumentException With a message naming the first invalid field.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public static function fromArray(array $payload, ?string $setBy = null, ?string $setAt = null): self {
		$none = self::none();

		return new self(
			self::securityFrom($payload['security'] ?? [], $none->security),
			self::regularFrom($payload['regular'] ?? [], $none->regular),
			self::statementFrom($payload['statement'] ?? ''),
			$setBy ?? self::optionalString($payload['setBy'] ?? null),
			$setAt ?? self::optionalString($payload['setAt'] ?? null),
		);
	}

	/**
	 * @param array<string, array{deadlineDays: ?int, handling: string}> $defaults
	 * @return array<string, array{deadlineDays: ?int, handling: string}>
	 */
	private static function securityFrom(mixed $raw, array $defaults): array {
		if (!is_array($raw)) {
			throw new InvalidArgumentException('security must be an object keyed by severity.');
		}

		$unknown = array_diff(array_map('strval', array_keys($raw)), self::SEVERITIES);
		if ($unknown !== []) {
			throw new InvalidArgumentException('security has an unknown severity: ' . implode(', ', $unknown) . '.');
		}

		$security = $defaults;
		foreach (self::SEVERITIES as $severity) {
			if (!array_key_exists($severity, $raw)) {
				continue;
			}
			$row = $raw[$severity];
			if (!is_array($row)) {
				throw new InvalidArgumentException('security.' . $severity . ' must be an object.');
			}
			$security[$severity] = [
				'deadlineDays' => self::deadlineFrom($severity, $row['deadlineDays'] ?? null),
				'handling' => self::oneOf('security.' . $severity . '.handling', $row['handling'] ?? self::HANDLING_SCHEDULED, self::SECURITY_HANDLINGS),
			];
		}

		return $security;
	}

	private static function deadlineFrom(string $severity, mixed $raw): ?int {
		if ($raw === null || $raw === '') {
			return null;
		}

		$days = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 ? (int)$raw : null);
		if ($days === null || $days < self::DEADLINE_MIN_DAYS || $days > self::DEADLINE_MAX_DAYS) {
			throw new InvalidArgumentException(
				'security.' . $severity . '.deadlineDays must be a whole number from '
				. self::DEADLINE_MIN_DAYS . ' to ' . self::DEADLINE_MAX_DAYS . ', or empty for none.'
			);
		}

		return $days;
	}

	/**
	 * @param array<string, string> $defaults
	 * @return array<string, string>
	 */
	private static function regularFrom(mixed $raw, array $defaults): array {
		if (!is_array($raw)) {
			throw new InvalidArgumentException('regular must be an object keyed by kind.');
		}

		$unknown = array_diff(array_map('strval', array_keys($raw)), self::KINDS);
		if ($unknown !== []) {
			throw new InvalidArgumentException('regular has an unknown kind: ' . implode(', ', $unknown) . '.');
		}

		$regular = $defaults;
		foreach (self::KINDS as $kind) {
			if (array_key_exists($kind, $raw)) {
				$regular[$kind] = self::oneOf('regular.' . $kind, $raw[$kind], self::REGULAR_HANDLINGS);
			}
		}

		return $regular;
	}

	private static function statementFrom(mixed $raw): string {
		if (!is_string($raw)) {
			throw new InvalidArgumentException('statement must be text.');
		}
		if (mb_strlen($raw) > self::STATEMENT_MAX_LENGTH) {
			throw new InvalidArgumentException('statement must be at most ' . self::STATEMENT_MAX_LENGTH . ' characters.');
		}

		return $raw;
	}

	/**
	 * @param list<string> $allowed
	 */
	private static function oneOf(string $field, mixed $raw, array $allowed): string {
		if (!is_string($raw) || !in_array($raw, $allowed, true)) {
			throw new InvalidArgumentException($field . ' must be one of: ' . implode(', ', $allowed) . '.');
		}

		return $raw;
	}

	private static function optionalString(mixed $raw): ?string {
		return is_string($raw) && $raw !== '' ? $raw : null;
	}

	/**
	 * Whether an admin recorded this policy, as opposed to the no-policy reading.
	 */
	public function isRecorded(): bool {
		return $this->setBy !== null;
	}

	/**
	 * How the fix for an advisory of this severity is handled; an unknown
	 * severity reads as `unknown`.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public function securityHandling(string $severity): string {
		return ($this->security[$severity] ?? $this->security[AdvisorySeverity::UNKNOWN])['handling'];
	}

	/**
	 * How a regular update of this kind is handled.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public function regularHandling(string $kind): string {
		return $this->regular[$kind] ?? self::HANDLING_AUTOMATIC;
	}

	/**
	 * The regular kinds set to by hand, in kind order.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public function manualKinds(): array {
		return array_values(array_filter(self::KINDS, fn (string $kind): bool => $this->regularHandling($kind) === self::HANDLING_MANUAL));
	}

	/**
	 * The highest severity among advisories, in the order critical, high,
	 * medium, low, unknown. No advisory, or none with a known severity, is
	 * `unknown`.
	 *
	 * @param list<mixed> $advisories Rows with a `severity`.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public static function highestSeverity(array $advisories): string {
		$found = array_map(
			static fn (mixed $advisory): string => AdvisorySeverity::normalize(is_array($advisory) ? ($advisory['severity'] ?? null) : null),
			$advisories,
		);
		foreach (self::SEVERITIES as $severity) {
			if (in_array($severity, $found, true)) {
				return $severity;
			}
		}

		return AdvisorySeverity::UNKNOWN;
	}

	/**
	 * The kind of the step from one version to another: `major` when the
	 * first number moves, `minor` when the second does, else `patch`.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public static function kindOf(string $from, string $to): string {
		$fromParts = explode('.', ltrim($from, 'vV'));
		$toParts = explode('.', ltrim($to, 'vV'));
		if (($fromParts[0] ?? '') !== ($toParts[0] ?? '')) {
			return self::KIND_MAJOR;
		}

		return ($fromParts[1] ?? '0') !== ($toParts[1] ?? '0') ? self::KIND_MINOR : self::KIND_PATCH;
	}

	/**
	 * The fields that differ from another policy, as `security.<severity>`,
	 * `regular.<kind>` and `statement`.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public function changedFrom(self $previous): array {
		$changed = [];
		foreach (self::SEVERITIES as $severity) {
			if ($this->security[$severity] !== $previous->security[$severity]) {
				$changed[] = 'security.' . $severity;
			}
		}
		foreach (self::KINDS as $kind) {
			if ($this->regular[$kind] !== $previous->regular[$kind]) {
				$changed[] = 'regular.' . $kind;
			}
		}
		if ($this->statement !== $previous->statement) {
			$changed[] = 'statement';
		}

		return $changed;
	}

	/**
	 * @return array{security: array<string, array{deadlineDays: ?int, handling: string}>, regular: array<string, string>, statement: string, setBy: ?string, setAt: ?string}
	 */
	public function toArray(): array {
		return [
			'security' => $this->security,
			'regular' => $this->regular,
			'statement' => $this->statement,
			'setBy' => $this->setBy,
			'setAt' => $this->setAt,
		];
	}
}
