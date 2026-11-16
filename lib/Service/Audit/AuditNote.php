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
 * A note every install audit entry written during a piece of work carries,
 * such as "Update group Registers" while the automatic update job installs a
 * group (autoupdate-run-batching D3). Like {@see AuditOrigin} it is ambient,
 * so the installers need not pass it along. Without a note nothing changes.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class AuditNote {
	/** @var list<string> */
	private static array $stack = [];

	/**
	 * Runs the work with the note in force.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
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
	 * The note in force, or null.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public static function current(): ?string {
		return self::$stack === [] ? null : self::$stack[count(self::$stack) - 1];
	}

	/**
	 * The message with the note in force put in front of it: the note alone
	 * when there is no message, the message unchanged without a note.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public static function applyTo(?string $message): ?string {
		$note = self::current();
		if ($note === null) {
			return $message;
		}

		return ($message === null || $message === '') ? $note : $note . ': ' . $message;
	}
}
