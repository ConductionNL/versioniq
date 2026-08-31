// SPDX-License-Identifier: EUPL-1.2
// Covers "Migration diff on downgrade" and "Last-known-good version record":
// @spec openspec/specs/migration-safety/spec.md
import { describe, expect, it, vi } from 'vitest'
import { orphanedMigrationsSummary, shouldOfferLkgRollback } from './migrationSafety.ts'

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
