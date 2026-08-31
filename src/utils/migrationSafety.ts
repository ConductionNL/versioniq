// Pure helpers for the migration-safety UI (downgrade guard, migration diff,
// last-known-good rollback). Kept free of Vue/component state so they can be
// unit-tested directly.
//
// @spec openspec/specs/migration-safety/spec.md
import { n, t } from '@nextcloud/l10n'

export type LkgRecord = {
	version: string
	recordedAt: string
	sourceId: string | null
}

export type AppLkgInfo = {
	installedVersion?: string | null
	lkg?: LkgRecord | null
}

/**
 * True when a "Roll back to last known good" action should be offered for
 * this app; see "Last-known-good version record" — Scenario "One-click
 * rollback target". Requires both a recorded last-known-good version and a
 * known installed version that differ from it.
 *
 * @param app
 */
export function shouldOfferLkgRollback(app: AppLkgInfo): boolean {
	const lkg = app.lkg
	const installed = app.installedVersion

	if (!lkg || !lkg.version || !installed) {
		return false
	}

	return lkg.version !== installed
}

/**
 * Renders the copy for the migration-diff section of the downgrade
 * confirmation dialog; see "Migration diff on downgrade".
 *
 * - `null` — the diff could not be computed; degrade to a generic warning.
 * - `[]` — no schema steps differ between the versions.
 * - non-empty — the target version lacks these migration steps.
 *
 * @param orphanedMigrations
 * @spec openspec/specs/migration-safety/spec.md
 */
export function orphanedMigrationsSummary(orphanedMigrations: string[] | null): string {
	if (orphanedMigrations === null) {
		return t('versioniq', 'Could not determine which database migrations differ between these versions. Downgrading can break database schema assumptions if migrations were already applied in the newer version.')
	}

	if (orphanedMigrations.length === 0) {
		return t('versioniq', 'No schema steps differ between the installed and target version.')
	}

	return n(
		'versioniq',
		'The target version lacks {count} database migration present in the installed version; its schema changes will remain.',
		'The target version lacks {count} database migrations present in the installed version; their schema changes will remain.',
		orphanedMigrations.length,
		{ count: orphanedMigrations.length },
	)
}
