// SPDX-License-Identifier: EUPL-1.2
// Covers "One-click enable" (#433): the enable button needs a password and
// an idle caller, the dialog stays open after confirming, and the password
// is forgotten when it closes.
// @spec openspec/specs/version-management/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import EnableAppDialog from './EnableAppDialog.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
// The dialog renders its buttons and a dismiss control, so a spec can drive
// every callback and the close (Escape / backdrop) path.
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'buttons'],
		emits: ['update:open'],
		template: '<div v-if="open" :data-name="name"><slot /><button v-for="b in buttons" :key="b.label" :data-label="b.label" :disabled="b.disabled" @click="b.callback()">{{ b.label }}</button><button data-label="dismiss" @click="$emit(\'update:open\', false)">x</button></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcPasswordField', () => ({
	default: { name: 'NcPasswordField', props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input type="password" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))

const props = { open: true, appId: 'calendar', busy: false, error: '' }

describe('EnableAppDialog buttons', () => {
	it('keeps enable disabled until a password is entered', async () => {
		const wrapper = mount(EnableAppDialog, { props })

		expect(wrapper.find('[data-label="Enable calendar"]').attributes('disabled')).toBeDefined()
		await wrapper.find('input[type="password"]').setValue('secret')
		expect(wrapper.find('[data-label="Enable calendar"]').attributes('disabled')).toBeUndefined()
	})

	it('keeps enable disabled while the caller is busy', async () => {
		const wrapper = mount(EnableAppDialog, { props: { ...props, busy: true } })

		await wrapper.find('input[type="password"]').setValue('secret')

		expect(wrapper.find('[data-label="Enable calendar"]').attributes('disabled')).toBeDefined()
	})

	it('confirming does not close the dialog', async () => {
		const wrapper = mount(EnableAppDialog, { props })

		await wrapper.find('input[type="password"]').setValue('secret')
		await wrapper.find('[data-label="Enable calendar"]').trigger('click')

		expect(wrapper.emitted('confirm')).toEqual([['secret']])
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it.each(['Cancel', 'dismiss'])('%s asks to close without confirming', async (label) => {
		const wrapper = mount(EnableAppDialog, { props })

		await wrapper.find(`[data-label="${label}"]`).trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it('forgets the password when it closes', async () => {
		const wrapper = mount(EnableAppDialog, { props })
		await wrapper.find('input[type="password"]').setValue('secret')

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect((wrapper.find('input[type="password"]').element as HTMLInputElement).value).toBe('')
		expect(wrapper.find('[data-label="Enable calendar"]').attributes('disabled')).toBeDefined()
	})

	it('shows no error paragraph without an error', () => {
		const wrapper = mount(EnableAppDialog, { props })

		expect(wrapper.find('[data-testid="enable-app-error"]').exists()).toBe(false)
	})
})
