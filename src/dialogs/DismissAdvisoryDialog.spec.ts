// SPDX-License-Identifier: EUPL-1.2
// The dismiss dialog sends a reason from the fixed list, a trimmed comment and
// an end date, which a planned fix needs; it hands the new triage map up and
// closes, or stays open showing a refusal.
// @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DismissAdvisoryDialog from './DismissAdvisoryDialog.vue'
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
 */
async function openDialog () {
	const wrapper = mount(DismissAdvisoryDialog, { props: { open: false, appId: ':server', advisoryId: 'GHSA-s' } })
	await wrapper.setProps({ open: true })
	return wrapper
}

describe('DismissAdvisoryDialog', () => {
	beforeEach(() => {
		mockedWrite.mockReset()
	})

	it('names the advisory and lists every reason', async () => {
		const wrapper = await openDialog()

		expect(wrapper.find('[data-name]').attributes('data-name')).toBe('Dismiss GHSA-s')
		expect(wrapper.findAll('input[type="radio"]').map((r) => r.attributes('value'))).toEqual(['not_used', 'inaccurate', 'tolerable_risk', 'mitigated', 'fix_planned'])
	})

	it('keeps Dismiss disabled until a reason is picked, and for a planned fix until an end date is set', async () => {
		const wrapper = await openDialog()
		expect(wrapper.find('[data-label="Dismiss advisory"]').attributes('disabled')).toBeDefined()

		await wrapper.find('input[value="fix_planned"]').trigger('change')
		expect(wrapper.find('[data-label="Dismiss advisory"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-field="date"]').attributes('aria-label')).toBe('End date')

		await wrapper.find('[data-field="date"]').setValue('2026-12-01')
		expect(wrapper.find('[data-label="Dismiss advisory"]').attributes('disabled')).toBeUndefined()
	})

	it('sends the reason, the trimmed comment and the end date, emits the map and closes', async () => {
		const triage = { ':server|GHSA-s': { dismissal: { reason: 'fix_planned' } } }
		mockedWrite.mockResolvedValue({ payload: { triage } } as never)
		const wrapper = await openDialog()

		await wrapper.find('input[value="fix_planned"]').trigger('change')
		await wrapper.find('textarea').setValue('  Patch in the next release  ')
		await wrapper.find('[data-field="date"]').setValue('2026-12-01')
		await wrapper.find('[data-label="Dismiss advisory"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('POST', '/ocs/v2.php/apps/versioniq/api/advisory-triage/dismiss', {
			appId: ':server', advisoryId: 'GHSA-s', reason: 'fix_planned', comment: 'Patch in the next release', until: '2026-12-01',
		})
		expect(wrapper.emitted('dismissed')).toEqual([[triage]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('sends no end date when none is set, and stays open showing a refusal', async () => {
		mockedWrite.mockResolvedValue({ payload: {}, error: 'Forbidden' } as never)
		const wrapper = await openDialog()

		await wrapper.find('input[value="not_used"]').trigger('change')
		await wrapper.find('[data-label="Dismiss advisory"]').trigger('click')
		await flushPromises()

		expect(mockedWrite.mock.calls[0][2]).toEqual({ appId: ':server', advisoryId: 'GHSA-s', reason: 'not_used', comment: '', until: '' })
		expect(wrapper.find('[role="alert"]').text()).toBe('Forbidden')
		expect(wrapper.emitted('dismissed')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})
})
