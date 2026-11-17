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
 * What one sweep's read of the NCSC-NL directory found (advisories-ncsc-feed
 * D2): the index it ends with, how far the cursor got, how many documents it
 * read and matched, and why it stopped early, if it did.
 *
 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
 * @psalm-api
 */
final class NcscRead {
	/** Documents read this sweep, matched or not. */
	public int $read = 0;

	/** Documents read this sweep that named a Nextcloud product. */
	public int $matched = 0;

	/** Why the read stopped before the end, or null. */
	public ?string $error = null;

	/** Whether every pending line was processed. */
	public bool $caughtUp = true;

	/** The time, in seconds, the read must stop by. */
	public float $deadline = 0.0;

	/**
	 * @param array<string, array<string, mixed>> $index The index, keyed by tracking id.
	 * @param string $cursor The newest changes.csv time processed, '' before the first read.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 */
	public function __construct(
		public array $index,
		public string $cursor,
	) {
	}
}
