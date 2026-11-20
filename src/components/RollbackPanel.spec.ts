// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// The rollback section of the History tab: lists the snapshots, takes a named
// one, deletes one, and picks a snapshot by date before opening its plan.
// @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RollbackPanel from './RollbackPanel.vue'

const ocsGet = vi.fn()
const ocsWrite = vi.fn()
vi.mock('../ocs.ts', () => ({
	ocsGet: (...args: unknown[]) => ocsGet(...args),
	ocsWrite: (...args: unknown[]) => ocsWrite(...args),
}))
vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number) => (count === 1 ? one : many).replace('%n', String(count)),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled', 'type', 'ariaLabel'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', props: ['type'], template: '<div data-test="note"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'], template: '<input data-test="name" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' } }))
vi.mock('../dialogs/RollbackPlanDialog.vue', () => ({ default: { name: 'RollbackPlanDialog', props: ['open', 'snapshot', 'steps', 'pickedFor'], template: '<div v-if="open" data-test="dialog" :data-snapshot="snapshot?.id" :data-picked="pickedFor">{{ steps.length }}</div>' } }))

const snapshots = [
	{ id: 's25', takenAt: '2026-09-25T10:00:00+00:00', reason: 'manual', name: 'Before the October upgrade', appCount: 4 },
	{ id: 's20', takenAt: '2026-09-20T10:00:00+00:00', reason: 'before_auto_update', name: null, appCount: 1 },
]

beforeEach(() => {
	ocsGet.mockReset()
	ocsWrite.mockReset()
	ocsGet.mockImplementation(async (path: string, query: Record<string, string> = {}) => {
		if (path.endsWith('/plan')) {
			return { payload: { steps: [{ appId: 'openregister' }] } }
		}
		if (query.at) {
			return { payload: { snapshots, picked: query.at >= '2026-09-20' ? snapshots[1] : null } }
		}
		return { payload: { snapshots, picked: null } }
	})
	ocsWrite.mockResolvedValue({ payload: {} })
})

describe('RollbackPanel', () => {
	it('lists the snapshots with their name or reason and app count', async () => {
		const wrapper = mount(RollbackPanel)
		await flushPromises()

		expect(wrapper.find('[data-snapshot="s25"]').text()).toContain('Before the October upgrade')
		expect(wrapper.find('[data-snapshot="s25"]').text()).toContain('4 apps')
		expect(wrapper.find('[data-snapshot="s20"]').text()).toContain('Before automatic updates')
		expect(wrapper.find('[data-snapshot="s20"]').text()).toContain('1 app')
	})

	it('takes a named snapshot and reloads', async () => {
		const wrapper = mount(RollbackPanel)
		await flushPromises()
		await wrapper.find('[data-test="name"]').setValue(' Before the October upgrade ')
		await wrapper.findAll('button').find((b) => b.text() === 'Take a snapshot')!.trigger('click')
		await flushPromises()

		expect(ocsWrite).toHaveBeenCalledWith('POST', '/ocs/v2.php/apps/versioniq/api/snapshots', { name: 'Before the October upgrade' })
		expect(ocsGet).toHaveBeenCalledTimes(2)
	})

	it('deletes a snapshot', async () => {
		const wrapper = mount(RollbackPanel)
		await flushPromises()
		await wrapper.find('[data-snapshot="s20"]').findAll('button').find((b) => b.text() === 'Delete')!.trigger('click')
		await flushPromises()

		expect(ocsWrite).toHaveBeenCalledWith('DELETE', '/ocs/v2.php/apps/versioniq/api/snapshots/s20')
	})

	it('picks the newest snapshot on or before a date and opens its plan', async () => {
		const wrapper = mount(RollbackPanel)
		await flushPromises()
		await wrapper.find('[data-test="date"]').setValue('2026-09-23')
		await wrapper.findAll('button').find((b) => b.text() === 'Show the plan')!.trigger('click')
		await flushPromises()

		expect(ocsGet).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/snapshots', { at: '2026-09-23' })
		const dialog = wrapper.find('[data-test="dialog"]')
		expect(dialog.attributes('data-snapshot')).toBe('s20')
		expect(dialog.attributes('data-picked')).toBe('2026-09-23')
	})

	it('says so when no snapshot is that old', async () => {
		const wrapper = mount(RollbackPanel)
		await flushPromises()
		await wrapper.find('[data-test="date"]').setValue('2026-09-01')
		await wrapper.findAll('button').find((b) => b.text() === 'Show the plan')!.trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-test="note"]').text()).toBe('No snapshot was taken on or before 2026-09-01.')
		expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)
	})
})
