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
 * The references every advisory source keeps next to the advisory id: its CVE
 * ids, its publication date and the link to its web page (design D1 of
 * advisories-per-app-detail). One reader for the GitHub advisory shape that
 * the Nextcloud feed, the forges and the App Store all send, so the three
 * cannot drift apart. A field the source does not carry stays empty.
 */
final class AdvisoryReferences {
	/**
	 * The CVE ids, publication date and link of one raw advisory record.
	 *
	 * CVE ids come from `cve_id` (or `cveId`) and from every `identifiers`
	 * entry of type CVE, upper-cased, deduplicated, in the order met. The
	 * date is kept only when it parses, as an ISO 8601 string in UTC. The
	 * link is kept only when it is an http(s) URL of a web page, never an API
	 * resource.
	 *
	 * @param array<array-key, mixed> $record
	 * @return array{cveIds: list<string>, publishedAt: ?string, url: ?string}
	 *
	 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
	 */
	public static function from(array $record): array {
		return [
			'cveIds' => self::cveIds($record),
			'publishedAt' => self::publishedAt($record['published_at'] ?? $record['publishedAt'] ?? null),
			'url' => self::url($record['html_url'] ?? $record['url'] ?? null),
		];
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @return list<string>
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#1.1
	 */
	private static function cveIds(array $record): array {
		/** @var mixed $first */
		$first = $record['cve_id'] ?? $record['cveId'] ?? null;
		$candidates = is_string($first) ? [$first] : [];
		/** @var mixed $identifiers */
		$identifiers = $record['identifiers'] ?? null;
		if (is_array($identifiers)) {
			/** @var mixed $identifier */
			foreach ($identifiers as $identifier) {
				/** @var mixed $type */
				$type = is_array($identifier) ? ($identifier['type'] ?? null) : null;
				/** @var mixed $value */
				$value = is_array($identifier) ? ($identifier['value'] ?? null) : null;
				if (is_string($type) && strtoupper($type) === 'CVE' && is_string($value)) {
					$candidates[] = $value;
				}
			}
		}

		$ids = [];
		foreach ($candidates as $candidate) {
			$candidate = strtoupper(trim($candidate));
			if (preg_match('/^CVE-\d{4}-\d{4,}$/', $candidate) === 1 && !in_array($candidate, $ids, true)) {
				$ids[] = $candidate;
			}
		}

		return $ids;
	}

	/**
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#1.1
	 */
	private static function publishedAt(mixed $value): ?string {
		if (!is_string($value) || trim($value) === '') {
			return null;
		}
		try {
			$date = new \DateTimeImmutable(trim($value));
		} catch (\Exception) {
			return null;
		}

		return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}

	/**
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#1.1
	 */
	private static function url(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		$scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));

		if (!in_array($scheme, ['http', 'https'], true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
			return null;
		}

		// GitHub's `url` is the REST resource, not a page a person can read;
		// only `html_url` is. An API host is never offered as the link.
		$host = strtolower((string)parse_url($value, PHP_URL_HOST));

		return str_starts_with($host, 'api.') ? null : $value;
	}
}
