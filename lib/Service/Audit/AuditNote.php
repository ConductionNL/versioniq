<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Audit;

/**
 * A note every audit row written during a piece of work carries after its own
 * message, such as why the automatic update installed a version before its
 * minimum age.
 *
 * The job runs one install inside {@see during()}, so the row the installer
 * writes for it says so without every installer taking the note as an
 * argument; the same shape as {@see AuditOrigin}.
 *
 * @psalm-api
 */
final class AuditNote {
	/** @var list<string> */
	private static array $stack = [];

	/**
	 * Run the work with this note on every audit row it writes.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public static function during(string $note, callable $work): mixed {
		self::$stack[] = $note;
		try {
			return $work();
		} finally {
			array_pop(self::$stack);
		}
	}

	/**
	 * The message with the current note after it; the message unchanged outside {@see during()}.
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public static function append(?string $message): ?string {
		if (self::$stack === []) {
			return $message;
		}

		$note = self::$stack[count(self::$stack) - 1];
		if ($message === null || trim($message) === '') {
			return $note;
		}

		return rtrim($message) . ' (' . $note . ')';
	}
}
