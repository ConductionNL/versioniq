// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
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
	previous?: { version: string, recordedAt: string, sourceId: string | null } | null
}

export type LkgRollbackTarget = {
	version: string
	label: string
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
 * The rollback targets an app card offers: the version that ran before the
 * last successful install ("Roll back to <previous>"), and the last good
 * version when it differs from both the installed version and `previous`.
 * A record written before `previous` existed offers its version as before.
 *
 * @param app The card's installed version and last-known-good record.
 * @spec openspec/changes/safety-rollback-backup-and-preflight/specs/migration-safety/spec.md
 */
export function lkgRollbackTargets(app: AppLkgInfo): LkgRollbackTarget[] {
	const lkg = app.lkg
	const installed = app.installedVersion
	if (!lkg || !installed) {
		return []
	}

	const previous = lkg.previous?.version || ''
	const targets: LkgRollbackTarget[] = []
	if (previous !== '' && previous !== installed) {
		targets.push({ version: previous, label: t('versioniq', 'Roll back to {version}', { version: previous }) })
	}
	if (lkg.version && lkg.version !== installed && lkg.version !== previous) {
		targets.push({
			version: lkg.version,
			label: previous === ''
				? t('versioniq', 'Roll back to {version}', { version: lkg.version })
				: t('versioniq', 'Roll back to the last good version ({version})', { version: lkg.version }),
		})
	}

	return targets
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

// Upgrade migration preview (safety-upgrade-migration-preview, design D3/D5).
// The advice names a size class, never a duration in seconds.

export type MigrationPreviewTable = {
	name: string
	rows: number | null
	sizeClass: 'new' | 'small' | 'large' | 'very_large' | 'unknown' | string
}

export type MigrationPreviewAction = {
	attribute: string
	table: string
	name: string
	description: string
}

export type MigrationPreviewStep = {
	name: string
	actions: MigrationPreviewAction[]
	tables: MigrationPreviewTable[]
	dataStep: boolean
	describes: boolean
}

export type MigrationPreview = {
	steps: MigrationPreviewStep[]
	repairSteps: string[]
	estimated: boolean
}

/**
 * The advice for a table's size class.
 *
 * @param sizeClass `new`, `small`, `large`, `very_large`, or anything else for unknown.
 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
 */
export function sizeClassAdvice(sizeClass: string): string {
	switch (sizeClass) {
	case 'new':
		return t('versioniq', 'New table; fast')
	case 'small':
		return t('versioniq', 'Takes seconds')
	case 'large':
		return t('versioniq', 'May take minutes')
	case 'very_large':
		return t('versioniq', 'May take long; plan a maintenance window')
	default:
		return t('versioniq', 'Size unknown')
	}
}

/** The text of each Nextcloud migration attribute, by short class name. */
const MIGRATION_ACTION_TEXTS: Record<string, () => string> = {
	CreateTable: () => t('versioniq', 'creates a table'),
	DropTable: () => t('versioniq', 'drops a table'),
	AddColumn: () => t('versioniq', 'adds a column'),
	DropColumn: () => t('versioniq', 'drops a column'),
	ModifyColumn: () => t('versioniq', 'changes a column'),
	AddIndex: () => t('versioniq', 'adds an index'),
	DropIndex: () => t('versioniq', 'drops an index'),
	DataCleansing: () => t('versioniq', 'cleans data'),
}

/**
 * What a Nextcloud migration attribute does; an unknown attribute is shown by its name.
 *
 * @param attribute The attribute's short class name.
 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
 */
export function migrationActionText(attribute: string): string {
	const text = Object.hasOwn(MIGRATION_ACTION_TEXTS, attribute) ? MIGRATION_ACTION_TEXTS[attribute] : undefined
	return text ? text() : attribute
}

/**
 * The fixed texts of the preview panel.
 *
 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
 */
export function migrationPreviewTexts(): Record<'noChanges' | 'unreadable' | 'estimated' | 'dataStep' | 'undescribed' | 'repairSteps', string> {
	return {
		noChanges: t('versioniq', 'No database changes'),
		unreadable: t('versioniq', 'The database changes of this version could not be read.'),
		estimated: t('versioniq', 'Estimate: the list of migrations already run could not be read.'),
		dataStep: t('versioniq', 'Moves data; the time depends on the amount of data.'),
		undescribed: t('versioniq', 'This step does not describe what it changes.'),
		repairSteps: t('versioniq', 'Repair steps after the migrations'),
	}
}
