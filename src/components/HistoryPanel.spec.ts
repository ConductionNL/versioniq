// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Covers the History tab's period, security filter and export:
// @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import HistoryPanel from './HistoryPanel.vue'
import { ocsGet } from '../ocs.ts'

const downloads = vi.hoisted(() => [] as Array<{ name: string, type: string, content: string }>)

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn() }))
vi.mock('../utils/auditExport.ts', async (importOriginal) => ({
	...(await importOriginal<Record<string, unknown>>()),
	download: (name: string, type: string, content: string) => downloads.push({ name, type, content }),
}))

const mockedOcsGet = vi.mocked(ocsGet)

function install (id: number, extra: Record<string, unknown> = {}) {
  return {
	id,
	actorUid: 'admin',
	appId: 'fixtureapp',
	operation: 'install',
	fromVersion: '1.0.0',
	toVersion: '1.0.1',
	sourceId: null,
	status: 'success',
	message: null,
	createdAt: '2026-11-14 09:00:00',
	...extra,
}
}

const lastQuery = (): Record<string, unknown> => mockedOcsGet.mock.calls.at(-1)?.[1] as Record<string, unknown>

describe('HistoryPanel filters and export', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		downloads.length = 0
		mockedOcsGet.mockResolvedValue({ payload: { entries: [] } })
	})

	it('asks for the plain history until a filter is set', async () => {
		mount(HistoryPanel)
		await flushPromises()

		expect(lastQuery()).toEqual({ limit: 50, offset: 0 })
	})

	it('sends the period, the time zone and security only, and shows the fixed advisories', async () => {
		const wrapper = mount(HistoryPanel)
		await flushPromises()
		mockedOcsGet.mockResolvedValue({ payload: { entries: [install(2, { fixedAdvisories: [{ id: 'GHSA-1', severity: 'high' }] })] } })

		await wrapper.find('[data-testid="history-from"]').setValue('2026-11-14')
		await wrapper.find('[data-testid="history-to"]').setValue('2026-11-14')
		await wrapper.find('[data-testid="history-security-only"]').setValue(true)
		await flushPromises()

		const query = lastQuery()
		expect(query).toMatchObject({ limit: 50, offset: 0, from: '2026-11-14', to: '2026-11-14', securityOnly: 'true' })
		expect(typeof query.timeZone).toBe('string')
		expect(wrapper.find('[data-testid="history-fixed"]').text()).toBe('GHSA-1')
	})

	it('exports the filtered history as CSV with the fixed advisory ids', async () => {
		const wrapper = mount(HistoryPanel)
		await flushPromises()
		await wrapper.find('[data-testid="history-security-only"]').setValue(true)
		await flushPromises()
		mockedOcsGet.mockResolvedValue({ payload: { entries: [install(2, { fixedAdvisories: [{ id: 'GHSA-1', severity: 'high' }] })] } })

		await wrapper.find('[data-testid="history-export-csv"]').trigger('click')
		await flushPromises()

		expect(lastQuery()).toMatchObject({ securityOnly: 'true', limit: 200, offset: 0 })
		expect(downloads).toHaveLength(1)
		expect(downloads[0]?.type).toBe('text/csv')
		expect(downloads[0]?.name).toBe('versioniq-history-start-now.csv')
		expect((downloads[0]?.content ?? '').split('\r\n').slice(0, 2)).toEqual([
			'createdAt,actorUid,appId,operation,fromVersion,toVersion,sourceId,status,message,fixedAdvisories',
			'2026-11-14 09:00:00,admin,fixtureapp,install,1.0.0,1.0.1,,success,,GHSA-1',
		])
		expect(wrapper.find('[data-testid="history-export-notice"]').exists()).toBe(false)
	})

	it('says when the export stopped at 5000 entries', async () => {
		const wrapper = mount(HistoryPanel)
		await flushPromises()
		mockedOcsGet.mockImplementation(async (_path, query) => ({
			payload: { entries: Array.from({ length: Number((query as Record<string, unknown>).limit) }, (_, i) => install(i)) },
		}))

		await wrapper.find('[data-testid="history-export-json"]').trigger('click')
		await flushPromises()

		expect(downloads[0]?.type).toBe('application/json')
		expect(JSON.parse(downloads[0]?.content ?? '[]')).toHaveLength(5000)
		expect(wrapper.find('[data-testid="history-export-notice"]').text())
			.toBe('The export holds the first 5000 entries. Choose a shorter period for the rest.')
	})
})
