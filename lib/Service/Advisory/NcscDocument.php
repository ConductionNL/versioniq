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
 * One NCSC-NL CSAF document as Versioniq keeps it (advisories-ncsc-feed D2,
 * D6): the index entry stored per tracking id, and the advisory records the
 * correlation reads from it, one per target.
 *
 * Severity is the highest CVSS base severity among the vulnerabilities that
 * list the product as known affected, or `unknown`. NCSC-NL's own `Kans` and
 * `Schade` ratings are kept as given and never turned into a severity: NCSC-NL
 * defines them on its own scale.
 *
 * @psalm-api
 */
final class NcscDocument {
	/** The source an NCSC-NL record carries. */
	public const SOURCE = 'ncsc';

	/** The severity order, lowest first; a severity outside it ranks below all. */
	private const RANK = [AdvisorySeverity::LOW, AdvisorySeverity::MEDIUM, AdvisorySeverity::HIGH, AdvisorySeverity::CRITICAL];

	/**
	 * The index entry of a document that names a Nextcloud product, or null
	 * when it has no tracking id.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<array-key, mixed> $document The CSAF document.
	 * @param array{targets: list<array{target: string, range: ?string, productIds: list<string>}>, url: string} $found The matched targets and the file's URL.
	 * @return array<string, mixed>|null The entry.
	 */
	public static function entry(array $document, array $found): ?array {
		$header = is_array($document['document'] ?? null) ? $document['document'] : [];
		$id = $header['tracking']['id'] ?? null;
		if (!is_string($id) || $id === '') {
			return null;
		}

		return [
			'id' => $id,
			'title' => self::text($header['title'] ?? null),
			'published' => self::text($header['tracking']['initial_release_date'] ?? null),
			'url' => self::selfReference($header) ?? $found['url'],
			'cveIds' => self::cveIds(document: $document),
			'ratings' => ['likelihood' => self::note(header: $header, title: 'Kans'), 'damage' => self::note(header: $header, title: 'Schade')],
			'targets' => array_map(
				static fn (array $target): array => [
					'target' => $target['target'],
					'range' => $target['range'],
					'severity' => self::severity(document: $document, productIds: $target['productIds']),
				],
				$found['targets'],
			),
		];
	}

	/**
	 * The advisory records of the indexed documents, keyed by target, with the
	 * ranges each record covers for that target.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<string, array<string, mixed>> $index The index, keyed by tracking id.
	 * @return array<string, list<array<string, mixed>>> The records per target.
	 */
	public static function records(array $index): array {
		$byTarget = [];
		foreach ($index as $entry) {
			foreach (self::recordsOf(entry: $entry) as $target => $record) {
				$byTarget[$target][] = $record;
			}
		}

		return $byTarget;
	}

	/**
	 * One record per target of an index entry; several products of the same
	 * target merge their ranges and keep the highest severity.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<string, mixed> $entry The index entry.
	 * @return array<string, array<string, mixed>> The records, keyed by target.
	 */
	private static function recordsOf(array $entry): array {
		$records = [];
		foreach ((array)($entry['targets'] ?? []) as $target) {
			$key = (string)($target['target'] ?? '');
			$records[$key] ??= self::record(entry: $entry);
			$records[$key]['ranges'][] = $target['range'] ?? null;
			$records[$key]['severity'] = self::higher(a: $records[$key]['severity'], b: (string)($target['severity'] ?? ''));
		}

		return $records;
	}

	/**
	 * A record for one target, before its ranges are known. `affected` and
	 * `patchedVersions` stay empty: the version check is the ranges'.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<string, mixed> $entry The index entry.
	 * @return array<string, mixed>
	 */
	private static function record(array $entry): array {
		return [
			'id' => (string)($entry['id'] ?? ''),
			'source' => self::SOURCE,
			'severity' => AdvisorySeverity::UNKNOWN,
			'summary' => (string)($entry['title'] ?? ''),
			'cveIds' => (array)($entry['cveIds'] ?? []),
			'ratings' => (array)($entry['ratings'] ?? []),
			'published' => (string)($entry['published'] ?? ''),
			'url' => (string)($entry['url'] ?? ''),
			'ranges' => [],
			'affected' => [],
			'firstPatchedVersion' => null,
			'patchedVersions' => [],
		];
	}

	/**
	 * Whether the installed version is one a record covers: `affected` when a
	 * range includes it, `not_affected` when every range excludes it, and
	 * `version_not_stated` when no range says (`vers:unknown/*`, or one that
	 * cannot be read).
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param list<?string> $ranges The record's ranges.
	 * @param string $installed The installed version.
	 * @return string The match.
	 */
	public static function match(array $ranges, string $installed): string {
		$answers = self::answers(ranges: $ranges, installed: $installed);
		if (in_array(true, $answers, true)) {
			return 'affected';
		}

		return ($answers !== [] && !in_array(null, $answers, true)) ? 'not_affected' : 'version_not_stated';
	}

	/**
	 * Each range's answer for the installed version: true inside, false
	 * outside, null when the range states none.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param list<?string> $ranges The ranges.
	 * @return list<?bool>
	 */
	private static function answers(array $ranges, string $installed): array {
		$answers = [];
		foreach ($ranges as $range) {
			$answers[] = is_string($range) ? VersRange::matches($range, $installed) : null;
		}

		return $answers;
	}

	/**
	 * The highest CVSS base severity among the vulnerabilities that list one
	 * of the products as known affected, or `unknown`.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<array-key, mixed> $document The CSAF document.
	 * @param list<string> $productIds The product ids of one target.
	 */
	public static function severity(array $document, array $productIds): string {
		$severity = AdvisorySeverity::UNKNOWN;
		foreach ((array)($document['vulnerabilities'] ?? []) as $vulnerability) {
			$listed = array_intersect($productIds, (array)($vulnerability['product_status']['known_affected'] ?? []));
			if (is_array($vulnerability) && $listed !== []) {
				$severity = self::higher(a: $severity, b: self::scored(vulnerability: $vulnerability));
			}
		}

		return $severity;
	}

	/**
	 * The highest base severity of one vulnerability's CVSS v3 scores.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<array-key, mixed> $vulnerability The vulnerability.
	 */
	private static function scored(array $vulnerability): string {
		$severity = AdvisorySeverity::UNKNOWN;
		foreach ((array)($vulnerability['scores'] ?? []) as $score) {
			$severity = self::higher(a: $severity, b: AdvisorySeverity::normalize($score['cvss_v3']['baseSeverity'] ?? null));
		}

		return $severity;
	}

	/**
	 * The higher of two severities; `unknown` ranks below every known one.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 */
	private static function higher(string $a, string $b): string {
		$rankA = array_search($a, self::RANK, true);
		$rankB = array_search($b, self::RANK, true);
		if ($rankB === false) {
			return $a;
		}

		return ($rankA === false || $rankB > $rankA) ? $b : $a;
	}

	/**
	 * The CVE ids of the document's vulnerabilities, each once.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<array-key, mixed> $document The CSAF document.
	 * @return list<string>
	 */
	private static function cveIds(array $document): array {
		$ids = [];
		foreach ((array)($document['vulnerabilities'] ?? []) as $vulnerability) {
			$cve = is_array($vulnerability) ? ($vulnerability['cve'] ?? null) : null;
			if (is_string($cve) && $cve !== '') {
				$ids[$cve] = true;
			}
		}

		return array_keys($ids);
	}

	/**
	 * The text of the document note with this title, or null.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<array-key, mixed> $header The CSAF `document` object.
	 */
	private static function note(array $header, string $title): ?string {
		return self::pick(items: (array)($header['notes'] ?? []), where: ['key' => 'title', 'is' => $title, 'take' => 'text']);
	}
	/**
	 * The document's `self` reference URL, or null.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<array-key, mixed> $header The CSAF `document` object.
	 */
	private static function selfReference(array $header): ?string {
		return self::pick(items: (array)($header['references'] ?? []), where: ['key' => 'category', 'is' => 'self', 'take' => 'url']);
	}

	/**
	 * The `take` text of the first item whose `key` is `is`, or null.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array<array-key, mixed> $items The notes or references.
	 * @param array{key: string, is: string, take: string} $where Which item, and which of its fields.
	 */
	private static function pick(array $items, array $where): ?string {
		foreach ($items as $item) {
			if (is_array($item) && ($item[$where['key']] ?? null) === $where['is'] && is_string($item[$where['take']] ?? null)) {
				return $item[$where['take']];
			}
		}

		return null;
	}
	/**
	 * A string field, or '' when it is not one.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 */
	private static function text(mixed $value): string {
		return is_string($value) ? $value : '';
	}
}
