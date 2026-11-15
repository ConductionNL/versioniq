// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/specs/migration-safety/spec.md
import { describe, expect, it, vi } from 'vitest'
import { restoreOutcomeText } from './restoreOutcome.ts'

vi.mock('@nextcloud/l10n', () => ({
	getCanonicalLocale: () => 'en-GB',
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

describe('restoreOutcomeText', () => {
	it('names the app, the version and the date of the backup it was restored from', () => {
		expect(restoreOutcomeText(200, { appId: 'dossiq', version: '0.4.48', backupCreatedAt: '2026-10-01T09:00:00+00:00' }))
			.toBe('Restored dossiq 0.4.48 with its tables from the backup of 1 Oct 2026.')
	})

	it('points to a rollback when there is no backup', () => {
		expect(restoreOutcomeText(409, { message: 'There is no backup to restore dossiq from.', code: 'no_backup' }))
			.toBe('There is no backup to restore. Roll back to an earlier version instead.')
	})

	it('shows the server message of a failed restore, or a generic line without one', () => {
		expect(restoreOutcomeText(500, { message: 'The restore failed: disk full' })).toBe('The restore failed: disk full')
		expect(restoreOutcomeText(500, {})).toBe('Could not restore the installation.')
	})
})
