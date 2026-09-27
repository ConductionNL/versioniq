<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Advisory;

use OCA\Versioniq\Service\Source\SourceBinding;

/**
 * Capability a release source may implement to expose published security
 * advisories for an app under a given binding. Kept separate from
 * {@see \OCA\Versioniq\Service\Source\SourceInterface} so a source that
 * cannot answer advisories (or a future source) is not forced to, and so the
 * existing source drivers/tests are unaffected.
 *
 * Implementations MUST reuse the driver's existing HTTP + credential (PAT)
 * path — no new bespoke client, no secret stored outside the existing PAT
 * management. They MUST be read-only and MUST NOT throw on transient errors:
 * they return an empty advisory list with a populated `error` string so the
 * caller can surface the message without failing correlation for other apps.
 *
 * @psalm-api
 */
interface AdvisorySourceInterface {
	/**
	 * Lists published security advisories affecting the given app under this
	 * binding, newest information first is not required — ordering is the
	 * caller's concern.
	 *
	 * Each advisory record has:
	 *   - `id`: the advisory identifier (e.g. GHSA id or App Store advisory id)
	 *   - `severity`: one of `low` | `medium` | `high` | `critical` | `unknown`
	 *     (lower-cased). `unknown` means the advisory carries no severity, or
	 *     one outside the four levels; it is never guessed to a level. Every
	 *     source maps what it receives through {@see AdvisorySeverity::normalize()}
	 *     so the same advisory reads the same from any source (issue #443).
	 *   - `summary`: a short human-readable summary
	 *   - `affected`: a list of version-range clauses (e.g. `>= 1.0.0`, `< 1.2.3`)
	 *     that MUST ALL hold for a version to be affected (AND semantics). An
	 *     empty list means "all versions affected".
	 *   - `firstPatchedVersion`: the earliest version that resolves the
	 *     advisory, or null when none is published yet.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @return array{advisories: list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}>, error: ?string}
	 */
	public function listAdvisories(string $appId, SourceBinding $binding): array;
}
