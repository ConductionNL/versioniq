<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Release;

use OCA\Versioniq\Service\Release\ReleaseNotesReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The breaking marker and the known-issues section of release notes
 * (releases-version-facts 3.1).
 *
 * @covers \OCA\Versioniq\Service\Release\ReleaseNotesReader
 *
 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-a-version-its-publisher-marks-as-breaking-is-flagged
 */
final class ReleaseNotesReaderTest extends TestCase {
	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function breakingNotes(): array {
		return [
			'level-2 heading' => ["## Breaking changes\n- Dropped PHP 8.1", true],
			'level-4 singular with colon' => ["#### Breaking change:\nRenamed the API", true],
			'warning sign' => ["### ⚠ BREAKING CHANGES\n* config key renamed", true],
			'warning emoji shortcode' => ["## :warning: Breaking Changes", true],
			'closing hashes' => ['## Breaking changes ##', true],
			'conventional footer' => ["fix: tidy\n\nBREAKING CHANGE: the export endpoint moved", true],
			'conventional footer with hyphen' => ['BREAKING-CHANGE: removed the v1 routes', true],
			'marker past 8 KiB' => [str_repeat("- a fix line that is long enough\n", 300) . "\n## Breaking changes\n- gone", true],
			'mention in prose' => ['This release has no breaking changes.', false],
			'heading about something else' => ["## Changes that are breaking news\n", false],
			'lower-case footer' => ['breaking change: not a footer', false],
			'inside a code block' => ["```\n## Breaking changes\nBREAKING CHANGE: x\n```", false],
			'empty' => ['', false],
		];
	}

	#[DataProvider('breakingNotes')]
	public function testBreakingMarker(string $notes, bool $expected): void {
		$this->assertSame($expected, (new ReleaseNotesReader())->isBreaking($notes));
	}

	/**
	 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
	 */
	public function testTheKnownIssuesSectionEndsAtTheNextHeadingOfItsLevel(): void {
		$notes = "## Fixed\n- a bug\n\n## Known issues\n- Exports over 2 GB fail.\n  Workaround: split the export.\n### Details\nOnly on S3 storage.\n\n```\nocc versioniq:install --keep\n## not a heading\n```\n## Upgrading\nRun the migrations.";

		$this->assertSame(
			"- Exports over 2 GB fail.\n  Workaround: split the export.\n### Details\nOnly on S3 storage.\n\n```\nocc versioniq:install --keep\n## not a heading\n```",
			(new ReleaseNotesReader())->knownIssues($notes)
		);
	}

	/**
	 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
	 */
	public function testKnownProblemsToTheEndAndAbsentOrEmptySections(): void {
		$reader = new ReleaseNotesReader();
		$this->assertSame('Login loops on Safari 16.', $reader->knownIssues("# 3.2.0\n### Known problems:\nLogin loops on Safari 16.\n"));
		$this->assertNull($reader->knownIssues("## Fixed\n- a bug"));
		$this->assertNull($reader->knownIssues("## Known issues\n\n## Fixed\n- a bug"));
		$this->assertNull($reader->knownIssues('Known issues: none'));
		$this->assertNull($reader->knownIssues(''));
	}
}
