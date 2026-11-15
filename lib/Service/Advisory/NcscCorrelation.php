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
 * Readies one target's advisories for evaluation when NCSC-NL records are
 * among them (advisories-ncsc-feed D4, D5).
 *
 * An NCSC-NL record that shares a CVE id with another record of the same
 * target is folded into that record: its id goes into the other's
 * `relatedIds` and it is dropped, so the advisory counts once. Every NCSC-NL
 * record left gets its `match` against the installed version: `affected`,
 * `not_affected` or `version_not_stated`. Only `affected` makes it active.
 *
 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
 * @psalm-api
 */
final class NcscCorrelation {
	/**
	 * The advisories with NCSC-NL duplicates folded in and NCSC-NL records matched.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param list<array<string, mixed>> $advisories One target's advisories.
	 * @param string $installed The installed version.
	 * @return list<array<string, mixed>>
	 */
	public static function prepare(array $advisories, string $installed): array {
		[$others, $ncsc] = self::split(advisories: $advisories);
		foreach ($ncsc as $record) {
			$twin = self::twin(others: $others, record: $record);
			if ($twin === null) {
				$others[] = $record + ['match' => NcscDocument::match((array)$record['ranges'], $installed)];
				continue;
			}

			$others[$twin]['relatedIds'] = [...($others[$twin]['relatedIds'] ?? []), $record['id']];
		}

		return $others;
	}

	/**
	 * Whether an advisory is an NCSC-NL record.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<string, mixed> $advisory The advisory.
	 */
	public static function isNcsc(array $advisory): bool {
		return ($advisory['source'] ?? null) === NcscDocument::SOURCE;
	}

	/**
	 * The advisories that are not NCSC-NL records. An NCSC-NL record names no
	 * fixed version, so only the others decide a recommended one.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param list<array<string, mixed>> $advisories The advisories.
	 * @return list<array<string, mixed>>
	 */
	public static function withoutNcsc(array $advisories): array {
		return self::split(advisories: $advisories)[0];
	}

	/**
	 * The other advisories and the NCSC-NL records, each in order.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param list<array<string, mixed>> $advisories The advisories.
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
	 */
	private static function split(array $advisories): array {
		$others = [];
		$ncsc = [];
		foreach ($advisories as $advisory) {
			if (self::isNcsc(advisory: $advisory)) {
				$ncsc[] = $advisory;
			} else {
				$others[] = $advisory;
			}
		}

		return [$others, $ncsc];
	}

	/**
	 * The position of the first other advisory that shares a CVE id with the record, or null.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param list<array<string, mixed>> $others The other advisories.
	 * @param array<string, mixed> $record The NCSC-NL record.
	 */
	private static function twin(array $others, array $record): ?int {
		$cves = (array)($record['cveIds'] ?? []);
		foreach ($others as $position => $other) {
			if (array_intersect($cves, (array)($other['cveIds'] ?? [])) !== []) {
				return $position;
			}
		}

		return null;
	}
}
