// SPDX-License-Identifier: EUPL-1.2
// The assign dialog sends user:{uid} or group:{gid}, or an empty assignee to
// unassign; it says only a group's admins are notified.
// @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AssignAdvisoryDialog from './AssignAdvisoryDialog.vue'
import { ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'buttons'],
		emits: ['update:open'],
		template: '<div v-if="open" :data-name="name"><slot /><button v-for="b in buttons" :key="b.label" :data-label="b.label" :disabled="b.disabled" @click="b.callback()">{{ b.label }}</button></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div data-testid="note"><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: { name: 'NcCheckboxRadioSwitch', props: ['modelValue', 'value'], emits: ['update:modelValue'], template: '<label><input type="radio" :value="value" :checked="modelValue === value" @change="$emit(\'update:modelValue\', value)"><slot /></label>' },
}))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: { name: 'NcTextField', props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input data-field="text" :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))
vi.mock('@nextcloud/vue/components/NcTextArea', () => ({
	default: { name: 'NcTextArea', props: ['modelValue'], emits: ['update:modelValue'], template: '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
}))
vi.mock('@nextcloud/vue/components/NcDateTimePickerNative', () => ({
	default: { name: 'NcDateTimePickerNative', props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input data-field="date" :aria-label="label" @input="$emit(\'update:modelValue\', new Date($event.target.value + \'T12:00:00\'))">' },
}))
vi.mock('../ocs', () => ({ ocsWrite: vi.fn() }))

const mockedWrite = vi.mocked(ocsWrite)

/**
 * Mounts the dialog closed and opens it, as the panel does.
 *
 * @param current The advisory's current owner.
 */
async function openDialog (current: { type: 'user' | 'group', id: string } | null = null) {
	const wrapper = mount(AssignAdvisoryDialog, { props: { open: false, appId: 'openregister', advisoryId: 'GHSA-1', current } })
	await wrapper.setProps({ open: true })
	return wrapper
}

describe('AssignAdvisoryDialog', () => {
	beforeEach(() => {
		mockedWrite.mockReset()
	})

	it('assigns to an admin by user id, emits the map and closes', async () => {
		const triage = { 'openregister|GHSA-1': { assignee: { type: 'user', id: 'bob' } } }
		mockedWrite.mockResolvedValue({ payload: { triage } } as never)
		const wrapper = await openDialog()
		expect(wrapper.find('[data-label="Assign"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-label="Unassign"]').exists()).toBe(false)

		await wrapper.find('[data-field="text"]').setValue(' bob ')
		await wrapper.find('[data-label="Assign"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('POST', '/ocs/v2.php/apps/versioniq/api/advisory-triage/assign', { appId: 'openregister', advisoryId: 'GHSA-1', assignee: 'user:bob' })
		expect(wrapper.emitted('assigned')).toEqual([[triage]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('assigns to a group and says only its admins are notified', async () => {
		mockedWrite.mockResolvedValue({ payload: { triage: {} } } as never)
		const wrapper = await openDialog()
		expect(wrapper.find('[data-testid="note"]').exists()).toBe(false)

		await wrapper.find('input[value="group"]').trigger('change')
		expect(wrapper.find('[data-testid="note"]').text()).toBe('Only the admins in this group are notified.')
		await wrapper.find('[data-field="text"]').setValue('ops')
		await wrapper.find('[data-label="Assign"]').trigger('click')
		await flushPromises()

		expect(mockedWrite.mock.calls[0][2]).toEqual({ appId: 'openregister', advisoryId: 'GHSA-1', assignee: 'group:ops' })
	})

	it('starts from the current owner and can unassign', async () => {
		mockedWrite.mockResolvedValue({ payload: { triage: {} } } as never)
		const wrapper = await openDialog({ type: 'group', id: 'ops' })
		expect((wrapper.find('[data-field="text"]').element as HTMLInputElement).value).toBe('ops')

		await wrapper.find('[data-label="Unassign"]').trigger('click')
		await flushPromises()

		expect(mockedWrite.mock.calls[0][2]).toEqual({ appId: 'openregister', advisoryId: 'GHSA-1', assignee: '' })
		expect(wrapper.emitted('assigned')).toEqual([[{}]])
	})

	it('stays open showing a refused assignment', async () => {
		mockedWrite.mockResolvedValue({ payload: {}, error: 'assignee must be user:{uid} of an admin or group:{gid} of an existing group.' } as never)
		const wrapper = await openDialog()

		await wrapper.find('[data-field="text"]').setValue('carol')
		await wrapper.find('[data-label="Assign"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('assignee must be')
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})
})
