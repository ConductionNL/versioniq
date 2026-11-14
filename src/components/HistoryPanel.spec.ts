import { flushPromises, mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Every audit row says where it came from" (audit-attribution-and-forwarding task 1.3):
// @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-every-audit-row-says-where-it-came-from
import { beforeEach, describe, expect, it, vi } from 'vitest'
import HistoryPanel from './HistoryPanel.vue'
import { ocsGet } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['update:modelValue'],
		template: '<label>{{ inputLabel }}<select @change="$emit(\'update:modelValue\', options[$event.target.selectedIndex])"><option v-for="o in options" :key="o.id">{{ o.label }}</option></select></label>',
	},
}))

vi.mock('../ocs', () => ({ ocsGet: vi.fn() }))

const mockedOcsGet = vi.mocked(ocsGet)

function row (id: number, appId: string, origin: string | null | undefined) {
  return {
	id,
	actorUid: origin === 'web' ? 'alice' : 'system',
	appId,
	operation: 'install',
	fromVersion: '1.0.0',
	toVersion: '1.1.0',
	sourceId: null,
	status: 'success',
	message: null,
	createdAt: '2026-11-14 02:00:00',
	...(origin === undefined ? {} : { origin }),
}
}

describe('HistoryPanel origin', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
	})

	it('shows each row\'s origin, and unknown for a row from before origins were recorded', async () => {
		mockedOcsGet.mockResolvedValue({
			payload: {
				entries: [
					row(5, 'openregister', 'job'),
					row(4, 'calendar', 'cli'),
					row(3, 'mail', 'web'),
					row(2, 'deck', 'listener'),
					row(1, 'files', null),
					row(0, 'notes', undefined),
				],
			},
		})

		const wrapper = mount(HistoryPanel)
		await flushPromises()

		const cells = wrapper.findAll('[data-testid="history-origin"]').map((cell) => cell.text())
		expect(cells).toEqual(['Automatic', 'Command line', 'Admin page', 'Drift check', 'Unknown', 'Unknown'])
		const origins = wrapper.findAll('[data-testid="history-row"]').map((tr) => tr.attributes('data-origin'))
		expect(origins).toEqual(['job', 'cli', 'web', 'listener', 'unknown', 'unknown'])
		expect(wrapper.text()).toContain('Origin')
	})

	it('asks for every row until an origin is chosen, then only that origin', async () => {
		mockedOcsGet.mockResolvedValue({ payload: { entries: [] } })

		const wrapper = mount(HistoryPanel)
		await flushPromises()
		expect(mockedOcsGet).toHaveBeenLastCalledWith('/ocs/v2.php/apps/versioniq/api/audit', { limit: 50, offset: 0 })

		const select = wrapper.find('[data-testid="history-origin-filter"] select')
		const options = select.findAll('option').map((option) => option.text())
		expect(options).toEqual(['All origins', 'Automatic', 'Command line', 'Admin page', 'Drift check', 'Unknown'])

		;(select.element as HTMLSelectElement).selectedIndex = 1
		await select.trigger('change')
		await flushPromises()
		expect(mockedOcsGet).toHaveBeenLastCalledWith('/ocs/v2.php/apps/versioniq/api/audit', { limit: 50, offset: 0, origin: 'job' })

		;(select.element as HTMLSelectElement).selectedIndex = 5
		await select.trigger('change')
		await flushPromises()
		expect(mockedOcsGet).toHaveBeenLastCalledWith('/ocs/v2.php/apps/versioniq/api/audit', { limit: 50, offset: 0, origin: 'unknown' })
	})

	it('keeps the app scope beside the origin', async () => {
		mockedOcsGet.mockResolvedValue({ payload: { entries: [] } })

		const wrapper = mount(HistoryPanel, { props: { appId: 'openregister' } })
		await flushPromises()
		const select = wrapper.find('[data-testid="history-origin-filter"] select')
		;(select.element as HTMLSelectElement).selectedIndex = 2
		await select.trigger('change')
		await flushPromises()

		expect(mockedOcsGet).toHaveBeenLastCalledWith('/ocs/v2.php/apps/versioniq/api/audit', { limit: 50, offset: 0, appId: 'openregister', origin: 'cli' })
	})
})
