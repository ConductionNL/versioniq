<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryReferences;
use PHPUnit\Framework\TestCase;

/**
 * advisories-per-app-detail D1: the CVE ids, date and link every source keeps.
 *
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
 */
final class AdvisoryReferencesTest extends TestCase {
	public function testReadsAGithubAdvisory(): void {
		$references = AdvisoryReferences::from([
			'cve_id' => 'CVE-2026-1111',
			'identifiers' => [
				['type' => 'GHSA', 'value' => 'GHSA-aaaa-bbbb-cccc'],
				['type' => 'CVE', 'value' => 'CVE-2026-1111'],
				['type' => 'cve', 'value' => 'cve-2025-0042'],
			],
			'published_at' => '2026-08-01T12:00:00+02:00',
			'html_url' => 'https://github.com/nextcloud/security-advisories/security/advisories/GHSA-aaaa-bbbb-cccc',
		]);

		self::assertSame(['CVE-2026-1111', 'CVE-2025-0042'], $references['cveIds']);
		self::assertSame('2026-08-01T10:00:00Z', $references['publishedAt']);
		self::assertSame('https://github.com/nextcloud/security-advisories/security/advisories/GHSA-aaaa-bbbb-cccc', $references['url']);
	}

	public function testAnEmptyRecordLeavesEverythingEmpty(): void {
		self::assertSame(['cveIds' => [], 'publishedAt' => null, 'url' => null], AdvisoryReferences::from([]));
	}

	public function testRejectsValuesThatAreNotWhatTheyClaim(): void {
		$references = AdvisoryReferences::from([
			'cve_id' => 'not a cve',
			'identifiers' => 'CVE-2026-1111',
			'published_at' => 'yesterday-ish nonsense',
			'html_url' => 'javascript:alert(1)',
		]);

		self::assertSame(['cveIds' => [], 'publishedAt' => null, 'url' => null], $references);
	}

	public function testNeverOffersTheApiResourceAsTheLink(): void {
		self::assertNull(AdvisoryReferences::from(['url' => 'https://api.github.com/repos/a/b/security-advisories/GHSA-x'])['url']);
		self::assertSame('https://apps.nextcloud.com/advisories/1', AdvisoryReferences::from(['url' => 'https://apps.nextcloud.com/advisories/1'])['url']);
	}

	public function testReadsTheCamelCaseShapeAppStoreEntriesMayUse(): void {
		$references = AdvisoryReferences::from(['cveId' => 'CVE-2024-12345', 'publishedAt' => '2024-02-03']);

		self::assertSame(['CVE-2024-12345'], $references['cveIds']);
		self::assertSame('2024-02-03T00:00:00Z', $references['publishedAt']);
	}
}
