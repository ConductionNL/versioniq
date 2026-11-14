<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Release;

/**
 * Reads two facts a publisher writes into a version's release notes
 * (releases-version-facts D3, D4): whether the publisher marks the release
 * as breaking, and its "Known issues" section.
 *
 * Both read the full notes, before the picker's truncation, so a marker at
 * the end of a long changelog is not lost.
 */
final class ReleaseNotesReader {
	/** A markdown heading line: its hashes and its text. */
	private const HEADING = '/^\s{0,3}(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*$/';

	/** A breaking heading's text, with or without the warning sign release tools put in front. */
	private const BREAKING_TITLE = '/^(?:(?:⚠️|⚠|:warning:|💥|:boom:)\s*)?breaking[ \t]+changes?\s*:?$/iu';

	/** The Conventional Commits footer. */
	private const BREAKING_FOOTER = '/^BREAKING[ -]CHANGE:/';

	/** A known-issues heading's text. */
	private const KNOWN_ISSUES_TITLE = '/^(?:(?:⚠️|⚠|:warning:)\s*)?known[ \t]+(?:issues|problems)\s*:?$/iu';

	/**
	 * Whether the publisher marks the release as breaking: a heading
	 * "Breaking changes" (or "Breaking change") at any level, or a
	 * `BREAKING CHANGE:` / `BREAKING-CHANGE:` footer line.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-a-version-its-publisher-marks-as-breaking-is-flagged
	 */
	public function isBreaking(string $notes): bool {
		foreach (self::lines($notes) as [$line, $fenced]) {
			if ($fenced === true) {
				continue;
			}

			if (preg_match(self::BREAKING_FOOTER, $line) === 1) {
				return true;
			}

			$heading = self::heading($line);
			if ($heading !== null && preg_match(self::BREAKING_TITLE, $heading[1]) === 1) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The text under a "Known issues" or "Known problems" heading, up to the
	 * next heading of the same or a higher level; null when there is none or
	 * it is empty.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
	 */
	public function knownIssues(string $notes): ?string {
		$level = null;
		$collected = [];
		foreach (self::lines($notes) as [$line, $fenced]) {
			$heading = $fenced ? null : self::heading($line);
			if ($level === null) {
				if ($heading !== null && preg_match(self::KNOWN_ISSUES_TITLE, $heading[1]) === 1) {
					$level = $heading[0];
				}

				continue;
			}

			if ($heading !== null && $heading[0] <= $level) {
				break;
			}

			$collected[] = $line;
		}

		$text = trim(implode("\n", $collected));
		if ($level === null || $text === '') {
			return null;
		}

		return $text;
	}

	/**
	 * A heading's level and text, or null when the line is not a heading.
	 *
	 * @return array{int, string}|null
	 */
	private static function heading(string $line): ?array {
		if (preg_match(self::HEADING, $line, $m) !== 1) {
			return null;
		}

		return [strlen($m[1]), $m[2]];
	}

	/**
	 * The notes as lines, each with whether it sits in a fenced code block
	 * (the fence lines included), where no heading or footer counts.
	 *
	 * @return list<array{string, bool}>
	 */
	private static function lines(string $notes): array {
		$lines = [];
		$fenced = false;
		foreach (preg_split('/\r\n|\r|\n/', $notes) ?: [] as $line) {
			if (preg_match('/^\s{0,3}(```|~~~)/', $line) === 1) {
				$lines[] = [$line, true];
				$fenced = !$fenced;
				continue;
			}

			$lines[] = [$line, $fenced];
		}

		return $lines;
	}
}
