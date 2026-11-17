// SPDX-License-Identifier: EUPL-1.2
// The successor list on the Settings tab: listing, adding and removing entries.
// @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SuccessorsPanel from './SuccessorsPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['type'], emits: ['click'], template: '<button :type="type ?? \'button\'" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', props: ['type'], template: '<div :data-type="type"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' } }))
vi.mock('../ocs.ts', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))

const BASE = '/ocs/v2.php/apps/versioniq/api/v1/successors'
const SHIPPED = { from: 'app_versions', to: 'versioniq', source: 'github:ConductionNL/versioniq', custom: false }
const CUSTOM = { from: 'oldmaps', to: 'maps', source: 'appstore', custom: true }

describe('SuccessorsPanel', () => {
	beforeEach(() => {
		vi.mocked(ocsGet).mockReset().mockResolvedValue({ payload: { successors: [SHIPPED, CUSTOM] }, error: null } as never)
		vi.mocked(ocsWrite).mockReset().mockResolvedValue({ payload: {}, error: null } as never)
	})

	it('lists the shipped and the admin entries, and only the admin entry can be removed', async () => {
		const wrapper = mount(SuccessorsPanel)
		await flushPromises()

		const rows = wrapper.findAll('[data-testid="successor-entry"]')
		expect(ocsGet).toHaveBeenCalledWith(BASE)
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('app_versions is succeeded by versioniq from github:ConductionNL/versioniq')
		expect(rows[0].text()).toContain('(shipped with Versioniq)')
		expect(rows[0].find('[data-testid="successor-remove"]').exists()).toBe(false)
		expect(rows[1].find('[data-testid="successor-remove"]').exists()).toBe(true)
	})

	it('removes an admin entry and reloads the list', async () => {
		const wrapper = mount(SuccessorsPanel)
		await flushPromises()

		await wrapper.find('[data-testid="successor-remove"]').trigger('click')
		await flushPromises()

		expect(ocsWrite).toHaveBeenCalledWith('DELETE', `${BASE}/oldmaps/maps`)
		expect(ocsGet).toHaveBeenCalledTimes(2)
	})

	it('adds the entry in the form, trimmed, and clears the form', async () => {
		const wrapper = mount(SuccessorsPanel)
		await flushPromises()
		const inputs = wrapper.findAll('input')
		await inputs[0].setValue(' oldname ')
		await inputs[1].setValue('newname')

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(ocsWrite).toHaveBeenCalledWith('POST', BASE, { from: 'oldname', to: 'newname', source: 'appstore' })
		expect((wrapper.findAll('input')[0].element as HTMLInputElement).value).toBe('')
	})

	it('shows the refusal and keeps the form when the entry is refused', async () => {
		vi.mocked(ocsWrite).mockResolvedValue({ payload: {}, error: 'An app cannot be its own successor.' } as never)
		const wrapper = mount(SuccessorsPanel)
		await flushPromises()
		await wrapper.findAll('input')[0].setValue('same')
		await wrapper.findAll('input')[1].setValue('same')

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-type="error"]').text()).toBe('An app cannot be its own successor.')
		expect((wrapper.findAll('input')[0].element as HTMLInputElement).value).toBe('same')
		expect(ocsGet).toHaveBeenCalledTimes(1)
	})
})
