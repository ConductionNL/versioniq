<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

/**
 * Reads the affected-version clauses of an advisory as the sources publish
 * them: one comma-separated string, or a list of clause strings.
 */
final class AdvisoryClauses {
	/**
	 * The trimmed, non-empty clauses in $raw; none for any other value.
	 *
	 * @return list<string>
	 */
	public static function from(mixed $raw): array {
		if (is_string($raw)) {
			$raw = explode(',', $raw);
		}
		if (!is_array($raw)) {
			return [];
		}
		$clauses = [];
		/** @var mixed $clause */
		foreach ($raw as $clause) {
			if (is_string($clause) && trim($clause) !== '') {
				$clauses[] = trim($clause);
			}
		}

		return $clauses;
	}
}
