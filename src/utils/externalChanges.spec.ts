// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/audit-external-changes
import { describe, expect, it, vi } from 'vitest'
import { historyMessage, leftDisabledMarks, leftDisabledNotice, operationLabel } from './externalChanges.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

describe('externalChanges', () => {
	it('names the new operations and keeps an unknown one as stored', () => {
		expect(operationLabel('external_update')).toBe('Changed outside Versioniq')
		expect(operationLabel('external_disable')).toBe('Disabled outside Versioniq')
		expect(operationLabel('reactivate')).toBe('Enabled again after an update')
		expect(operationLabel('install')).toBe('Install')
		expect(operationLabel('something_new')).toBe('something_new')
	})

	it('shows the trigger of a change made outside Versioniq, and other messages as they are', () => {
		expect(historyMessage('external_update', 'occ app:update openregister')).toBe('Trigger: occ app:update openregister')
		expect(historyMessage('external_disable', 'POST /settings/apps/disable')).toBe('Trigger: POST /settings/apps/disable')
		expect(historyMessage('reactivate', 'Enabled again after an update left it disabled.')).toBe('Enabled again after an update left it disabled.')
		expect(historyMessage('external_update', null)).toBe('')
		expect(historyMessage('install', '')).toBe('')
	})

	it('words the card notice by whether the app runs on this server', () => {
		expect(leftDisabledNotice({ toVersion: 'Nextcloud 34', compatible: true, compatibleVersion: null })).toBe('Disabled by the update to Nextcloud 34')
		expect(leftDisabledNotice({ toVersion: 'Nextcloud 34', compatible: false, compatibleVersion: '1.5.0' }))
			.toBe('Disabled by the update to Nextcloud 34. It does not run on this server; version 1.5.0 does.')
		expect(leftDisabledNotice({ toVersion: '4.4.0', compatible: false, compatibleVersion: null }))
			.toBe('Disabled by the update to 4.4.0. It does not run on this server.')
	})

	it('reads an empty map sent as a list, or nothing, as no marks', () => {
		const mark = { toVersion: '4.4.0', compatible: true, compatibleVersion: null }
		expect(leftDisabledMarks({ forms: mark })).toEqual({ forms: mark })
		expect(leftDisabledMarks([])).toEqual({})
		expect(leftDisabledMarks(undefined)).toEqual({})
		expect(leftDisabledMarks(null)).toEqual({})
	})
})
