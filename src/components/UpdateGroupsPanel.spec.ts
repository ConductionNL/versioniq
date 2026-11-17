// SPDX-License-Identifier: EUPL-1.2
// Covers "Apps in an update group are updated together or not at all" and
// "An admin limits how many automatic updates run" (the page):
// @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import UpdateGroupsPanel from './UpdateGroupsPanel.vue'
import { ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', props: ['modelValue', 'options', 'inputLabel', 'multiple'], emits: ['update:modelValue'], template: '<div class="nc-select" />' } }))
vi.mock('../ocs', () => ({ ocsWrite: vi.fn() }))

const mockedWrite = vi.mocked(ocsWrite)

const stored = {
	groups: [{ id: 'g1', name: 'Registers', apps: ['openregister', 'opencatalogi'] }],
	maxPerRun: 3,
	maxPerWeek: null,
}

/**
 * @param apps The apps that can join a group.
 */
async function mountPanel (apps: string[] = ['openregister', 'opencatalogi', 'deck', 'calendar']) {
	const wrapper = mount(UpdateGroupsPanel, { props: { apps, labels: { deck: 'Deck', calendar: 'Calendar' }, ...stored } })
	await flushPromises()
	return wrapper
}

describe('UpdateGroupsPanel', () => {
	beforeEach(() => {
		mockedWrite.mockReset()
	})

	it('shows the stored groups in order and the limits', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.findAll('[data-testid="update-group-name"]').map((name) => name.text())).toEqual(['Registers'])
		expect(wrapper.findAll('[data-testid="update-group-app"]').map((row) => row.find('span').text())).toEqual(['openregister', 'opencatalogi'])
		expect((wrapper.find('[data-testid="update-limit-run"]').element as HTMLInputElement).value).toBe('3')
		expect((wrapper.find('[data-testid="update-limit-week"]').element as HTMLInputElement).value).toBe('')
	})

	it('offers only the apps that are in no group yet', async () => {
		const wrapper = await mountPanel()

		const options = wrapper.findComponent({ name: 'NcSelect' }).props('options') as Array<{ id: string }>
		expect(options.map((option) => option.id)).toEqual(['calendar', 'deck'])
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('inputLabel')).toBe('Apps, in install order')
	})

	it('adds a group, reorders it and saves the whole list', async () => {
		mockedWrite.mockResolvedValue({ payload: { groups: [stored.groups[0], { id: 'g2', name: 'Office', apps: ['calendar', 'deck'] }] } })
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="update-group-new-name"]').setValue('  Office ')
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', [{ id: 'deck', label: 'Deck' }, { id: 'calendar', label: 'Calendar' }])
		await wrapper.find('[data-testid="update-group-add"]').trigger('click')
		const office = wrapper.findAll('[data-testid="update-group"]')[1]
		await office.findAll('[data-testid="update-group-down"]')[0].trigger('click')
		await wrapper.find('[data-testid="update-groups-save"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/auto-update/groups', {
			groups: [
				{ id: 'g1', name: 'Registers', apps: ['openregister', 'opencatalogi'] },
				{ name: 'Office', apps: ['calendar', 'deck'] },
			],
		})
		expect(wrapper.find('[data-testid="update-groups-notice"]').text()).toBe('Update groups saved.')
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('refuses a group of one in the form without sending anything', async () => {
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="update-group-new-name"]').setValue('Solo')
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', [{ id: 'deck', label: 'Deck' }])
		await wrapper.find('[data-testid="update-group-add"]').trigger('click')

		expect(wrapper.find('[data-testid="update-groups-error"]').text()).toBe('A group needs a name and at least two apps.')
		expect(wrapper.findAll('[data-testid="update-group"]')).toHaveLength(1)
		expect(mockedWrite).not.toHaveBeenCalled()
	})

	it('shows the server refusal and keeps the list', async () => {
		mockedWrite.mockResolvedValue({ payload: {}, error: 'b is in two groups: "One" and "Two".' })
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="update-groups-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="update-groups-error"]').text()).toBe('b is in two groups: "One" and "Two".')
		expect(wrapper.findAll('[data-testid="update-group"]')).toHaveLength(1)
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('saves the limits, an empty one as no limit', async () => {
		mockedWrite.mockResolvedValue({ payload: { maxPerRun: 5, maxPerWeek: null } })
		const wrapper = await mountPanel()

		await wrapper.find('[data-testid="update-limit-run"]').setValue('5')
		await wrapper.find('[data-testid="update-limits-save"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/auto-update/settings', { maxPerRun: '5', maxPerWeek: '' })
		expect(wrapper.find('[data-testid="update-groups-notice"]').text()).toBe('Update limits saved.')
	})
})
