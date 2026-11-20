// SPDX-License-Identifier: EUPL-1.2
// Covers "Migration diff on downgrade" and "Last-known-good version record":
// @spec openspec/specs/migration-safety/spec.md
import { describe, expect, it, vi } from 'vitest'
import { lkgRollbackTargets, migrationActionText, migrationPreviewTexts, orphanedMigrationsSummary, shouldOfferLkgRollback, sizeClassAdvice } from './migrationSafety.ts'

function substitute (text: string, vars: Record<string, unknown> = {}) {
  return text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? ''))
}

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) => substitute(text, vars),
	n: (_app: string, textSingular: string, textPlural: string, count: number, vars: Record<string, unknown> = {}) =>
		substitute(count === 1 ? textSingular : textPlural, vars),
}))

describe('shouldOfferLkgRollback', () => {
	it('is false when there is no lkg record', () => {
		expect(shouldOfferLkgRollback({ installedVersion: '2.6.0', lkg: null })).toBe(false)
	})

	it('is false when the installed version is unknown', () => {
		expect(shouldOfferLkgRollback({
			installedVersion: null,
			lkg: { version: '2.5.0', recordedAt: '2026-07-23T12:00:00+00:00', sourceId: 'appstore' },
		})).toBe(false)
	})

	it('is false when the lkg version matches the installed version', () => {
		expect(shouldOfferLkgRollback({
			installedVersion: '2.5.0',
			lkg: { version: '2.5.0', recordedAt: '2026-07-23T12:00:00+00:00', sourceId: 'appstore' },
		})).toBe(false)
	})

	it('is true when installed at a broken version and lkg records an older good one', () => {
		// Scenario: "One-click rollback target".
		expect(shouldOfferLkgRollback({
			installedVersion: '2.6.0',
			lkg: { version: '2.5.0', recordedAt: '2026-07-23T12:00:00+00:00', sourceId: 'appstore' },
		})).toBe(true)
	})
})

describe('orphanedMigrationsSummary', () => {
	it('reports a generic warning when the diff is unavailable', () => {
		// Scenario: "Diff failure degrades gracefully".
		const summary = orphanedMigrationsSummary(null)

		expect(summary).toContain('Could not determine')
	})

	it('reports no schema drift for an empty diff', () => {
		// Scenario: "No schema drift".
		const summary = orphanedMigrationsSummary([])

		expect(summary).toBe('No schema steps differ between the installed and target version.')
	})

	it('names the orphaned step count for a single migration', () => {
		const summary = orphanedMigrationsSummary(['Version2040Date20260101000000'])

		expect(summary).toContain('1 database migration')
		expect(summary).not.toContain('migrations')
	})

	it('names the orphaned step count for multiple migrations', () => {
		// Scenario: "Diff names the orphaned steps".
		const summary = orphanedMigrationsSummary(['Version2040Date20260101000000', 'Version2041Date20260102000000'])

		expect(summary).toContain('2 database migrations')
	})
})

// @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
describe('sizeClassAdvice', () => {
	it('gives the advice of each size class and never a duration in seconds', () => {
		expect(sizeClassAdvice('new')).toBe('New table; fast')
		expect(sizeClassAdvice('small')).toBe('Takes seconds')
		expect(sizeClassAdvice('large')).toBe('May take minutes')
		expect(sizeClassAdvice('very_large')).toBe('May take long; plan a maintenance window')
		for (const sizeClass of ['new', 'small', 'large', 'very_large', 'unknown']) {
			expect(sizeClassAdvice(sizeClass)).not.toMatch(/\d/)
		}
	})

	it('reads an unknown or missing class as size unknown', () => {
		expect(sizeClassAdvice('unknown')).toBe('Size unknown')
		expect(sizeClassAdvice('')).toBe('Size unknown')
	})
})

describe('migrationActionText', () => {
	it('names what each migration attribute does', () => {
		expect(migrationActionText('AddIndex')).toBe('adds an index')
		expect(migrationActionText('CreateTable')).toBe('creates a table')
		expect(migrationActionText('ModifyColumn')).toBe('changes a column')
		expect(migrationActionText('DataCleansing')).toBe('cleans data')
	})

	it('shows an unknown attribute by its name rather than dropping it', () => {
		expect(migrationActionText('RenameTable')).toBe('RenameTable')
	})
})

describe('migrationPreviewTexts', () => {
	it('holds the fixed panel texts', () => {
		const texts = migrationPreviewTexts()

		expect(texts.noChanges).toBe('No database changes')
		expect(texts.dataStep).toBe('Moves data; the time depends on the amount of data.')
		expect(texts.undescribed).toBe('This step does not describe what it changes.')
	})
})

// @spec openspec/specs/migration-safety/spec.md
describe('lkgRollbackTargets', () => {
	const record = (version: string, previous?: string) => ({
		version,
		recordedAt: '2026-11-14T10:00:00+00:00',
		sourceId: 'appstore',
		previous: previous === undefined ? undefined : { version: previous, recordedAt: '2026-11-01T10:00:00+00:00', sourceId: 'appstore' },
	})

	it('offers the previous version when the last good version is installed', () => {
		expect(lkgRollbackTargets({ installedVersion: '0.4.46', lkg: record('0.4.46', '0.4.44') }))
			.toEqual([{ version: '0.4.44', label: 'Roll back to 0.4.44' }])
	})

	it('also offers the last good version when it differs from the installed and the previous one', () => {
		expect(lkgRollbackTargets({ installedVersion: '0.4.48', lkg: record('0.4.46', '0.4.44') })).toEqual([
			{ version: '0.4.44', label: 'Roll back to 0.4.44' },
			{ version: '0.4.46', label: 'Roll back to the last good version (0.4.46)' },
		])
	})

	it('offers a record without previous as before', () => {
		expect(lkgRollbackTargets({ installedVersion: '1.2.0', lkg: record('1.0.0') })).toEqual([{ version: '1.0.0', label: 'Roll back to 1.0.0' }])
		expect(lkgRollbackTargets({ installedVersion: '1.0.0', lkg: record('1.0.0') })).toEqual([])
	})

	it('offers nothing when the previous version is installed, or nothing is known', () => {
		expect(lkgRollbackTargets({ installedVersion: '0.4.44', lkg: record('0.4.44', '0.4.44') })).toEqual([])
		expect(lkgRollbackTargets({ installedVersion: '0.4.46', lkg: null })).toEqual([])
		expect(lkgRollbackTargets({ installedVersion: null, lkg: record('0.4.46', '0.4.44') })).toEqual([])
	})
})
