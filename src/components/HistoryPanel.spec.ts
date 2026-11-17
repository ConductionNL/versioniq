// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Covers "Trusted-source changes and cleared bindings are recorded": the
// History tab names the three operations (sources-trust-audit-and-offline-feed 1.2).
// @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/audit-trail/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import HistoryPanel from './HistoryPanel.vue'
import { ocsGet } from '../ocs.ts'
import { displayPattern, labelCarriesMessage, operationLabel } from '../utils/auditOperations.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', template: '<button><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../ocs', () => ({
	ocsGet: vi.fn(),
}))

const mockedOcsGet = vi.mocked(ocsGet)

function entry (id: number, operation: string, message: string | null, appId = 'versioniq') {
	return {
		id,
		actorUid: 'admin',
		appId,
		operation,
		fromVersion: null,
		toVersion: null,
		sourceId: null,
		status: 'success',
		message,
		createdAt: '2026-11-14 10:00:00',
	}
}

describe('audit operation labels', () => {
	it('names the trusted-source operations with the pattern people read', () => {
		expect(operationLabel({ operation: 'trust_source', message: 'codeberg.org/zuiddrecht/*' })).toBe('Trusted source added: codeberg.org/zuiddrecht')
		expect(operationLabel({ operation: 'untrust_source', message: 'git.example.org/team/' })).toBe('Trusted source removed: git.example.org/team')
		expect(operationLabel({ operation: 'unbind_source', message: 'Binding removed, was github:ConductionNL/dossiq' })).toBe('Source binding cleared')
	})

	it('shows any other operation as stored', () => {
		expect(operationLabel({ operation: 'install', message: null })).toBe('install')
		expect(operationLabel({ operation: 'trust_source', message: null })).toBe('Trusted source added: ')
	})

	it('only folds the message into the label for the trusted-source operations', () => {
		expect(labelCarriesMessage({ operation: 'trust_source', message: 'x' })).toBe(true)
		expect(labelCarriesMessage({ operation: 'unbind_source', message: 'x' })).toBe(false)
		expect(displayPattern(' codeberg.org/zuiddrecht/* ')).toBe('codeberg.org/zuiddrecht')
	})
})

describe('HistoryPanel', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
	})

	it('shows the trust, untrust and unbind entries with the others', async () => {
		mockedOcsGet.mockResolvedValue({
			payload: {
				entries: [
					entry(3, 'unbind_source', 'Binding removed, was github:ConductionNL/dossiq', 'dossiq'),
					entry(2, 'untrust_source', 'git.example.org/*'),
					entry(1, 'trust_source', 'codeberg.org/zuiddrecht/*'),
					entry(0, 'install', 'Installed'),
				],
			},
			error: null,
		} as never)

		const wrapper = mount(HistoryPanel)
		await flushPromises()

		const rows = wrapper.findAll('[data-testid="history-row"]')
		expect(rows.map((row) => row.attributes('data-operation'))).toEqual(['unbind_source', 'untrust_source', 'trust_source', 'install'])
		const labels = wrapper.findAll('[data-testid="history-operation"]').map((cell) => cell.text())
		expect(labels).toEqual(['Source binding cleared', 'Trusted source removed: git.example.org', 'Trusted source added: codeberg.org/zuiddrecht', 'install'])
		expect(rows[0].text()).toContain('Binding removed, was github:ConductionNL/dossiq')
		expect(rows[2].text()).not.toContain('codeberg.org/zuiddrecht/*')
	})
})
