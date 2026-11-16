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
 * What one read of the advisory feed has collected so far: the advisories
 * per target and the GHSA ids already taken, so a repeated id is skipped.
 */
final class FeedCollection {
	/** @var array<string, list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions: list<string>, cveIds?: list<string>, publishedAt?: ?string, url?: ?string}>> */
	public array $byTarget = [];

	/** @var array<string, true> */
	public array $seen = [];
}
