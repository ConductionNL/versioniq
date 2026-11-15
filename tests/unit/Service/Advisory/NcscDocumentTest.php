<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\NcscDocument;
use PHPUnit\Framework\TestCase;

/**
 * An NCSC-NL record's version match and per-target records (advisories-ncsc-feed D4, D6).
 *
 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
 */
final class NcscDocumentTest extends TestCase {
	public function testARangeThatIncludesTheVersionIsAffected(): void {
		self::assertSame('affected', NcscDocument::match(['vers:generic/<31.0.9', 'vers:unknown/*'], '31.0.2'));
	}

	public function testRangesThatAllExcludeTheVersionAreNotAffected(): void {
		self::assertSame('not_affected', NcscDocument::match(['vers:generic/<30.0.0', 'vers:generic/>=32.0.0'], '31.0.2'));
	}

	public function testAnUnknownRangeOrNoneIsNotStated(): void {
		self::assertSame('version_not_stated', NcscDocument::match(['vers:unknown/*'], '31.0.2'));
		self::assertSame('version_not_stated', NcscDocument::match([null], '31.0.2'));
		self::assertSame('version_not_stated', NcscDocument::match(['vers:generic/<30.0.0', 'garbage'], '31.0.2'));
		self::assertSame('version_not_stated', NcscDocument::match([], '31.0.2'));
	}

	public function testTwoProductsOfOneTargetMergeIntoOneRecordWithTheHigherSeverity(): void {
		$records = NcscDocument::records(['NCSC-1' => [
			'id' => 'NCSC-1',
			'title' => 'T',
			'targets' => [
				['target' => ':server', 'range' => 'vers:generic/<31.0.9', 'severity' => 'medium'],
				['target' => ':server', 'range' => 'vers:generic/<30.0.12', 'severity' => 'critical'],
				['target' => 'spreed', 'range' => null, 'severity' => 'unknown'],
			],
		]]);

		self::assertSame([':server', 'spreed'], array_keys($records));
		self::assertSame(['vers:generic/<31.0.9', 'vers:generic/<30.0.12'], $records[':server'][0]['ranges']);
		self::assertSame('critical', $records[':server'][0]['severity']);
		self::assertSame('unknown', $records['spreed'][0]['severity']);
	}

	public function testAnEntryWithoutATrackingIdIsNotIndexed(): void {
		self::assertNull(NcscDocument::entry(['document' => ['title' => 'x']], ['targets' => [], 'url' => 'u']));
	}

	public function testTheSelfReferenceIsTheLinkWhenTheDocumentHasOne(): void {
		$entry = NcscDocument::entry(
			['document' => ['tracking' => ['id' => 'NCSC-1'], 'references' => [['category' => 'external', 'url' => 'https://x'], ['category' => 'self', 'url' => 'https://advisories.ncsc.nl/advisory?id=NCSC-1']]]],
			['targets' => [], 'url' => 'https://advisories.ncsc.nl/csaf/v2/2026/ncsc-1.json'],
		);

		self::assertSame('https://advisories.ncsc.nl/advisory?id=NCSC-1', $entry['url']);
		self::assertSame(['likelihood' => null, 'damage' => null], $entry['ratings']);
	}
}
