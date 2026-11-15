<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

/**
 * A requested dismissal, checked: a reason from the fixed list, a comment of
 * at most {@see COMMENT_MAX} characters and an end date, which `fix_planned`
 * requires (advisories-triage, design D2).
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 *
 * @psalm-api
 */
final class Dismissal {
	/**
	 * The reasons an admin can give.
	 */
	public const REASONS = ['not_used', 'inaccurate', 'tolerable_risk', 'mitigated', 'fix_planned'];

	/**
	 * The longest comment kept, in characters.
	 */
	public const COMMENT_MAX = 1000;

	/**
	 * @param string $reason One of {@see REASONS}.
	 * @param string $comment The comment, possibly empty.
	 * @param ?string $until The end date as ISO 8601, or null for none.
	 */
	private function __construct(
		public readonly string $reason,
		public readonly string $comment,
		public readonly ?string $until,
	) {
	}

	/**
	 * A dismissal from the request's fields.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param array{reason: string, comment: string, until: string} $fields The trimmed fields.
	 * @throws TriageRefusal When a field cannot be accepted.
	 */
	public static function from(array $fields): self {
		if (in_array($fields['reason'], self::REASONS, true) === false) {
			throw TriageRefusal::invalid('reason must be one of: ' . implode(', ', self::REASONS) . '.');
		}

		if (mb_strlen($fields['comment']) > self::COMMENT_MAX) {
			throw TriageRefusal::invalid('comment must be at most ' . self::COMMENT_MAX . ' characters.');
		}

		return new self($fields['reason'], $fields['comment'], self::until(reason: $fields['reason'], until: $fields['until']));
	}

	/**
	 * The end date as ISO 8601, or null when none was given.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @throws TriageRefusal When the date cannot be read, or `fix_planned` has none.
	 */
	private static function until(string $reason, string $until): ?string {
		if ($until === '') {
			if ($reason === 'fix_planned') {
				throw TriageRefusal::invalid('A planned fix needs an end date.');
			}

			return null;
		}

		$time = AdvisoryTriageStore::time($until);
		if ($time === null) {
			throw TriageRefusal::invalid('until must be a date.');
		}

		return $time->format(DATE_ATOM);
	}
}
