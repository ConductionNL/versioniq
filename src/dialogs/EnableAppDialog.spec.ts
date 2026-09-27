// SPDX-License-Identifier: EUPL-1.2
// Covers "One-click enable" (#433):
// @spec openspec/specs/version-management/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import EnableAppDialog from './EnableAppDialog.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'buttons'],
		template: '<div v-if="open"><slot /><button v-for="b in buttons" :key="b.label" :data-label="b.label" :disabled="b.disabled" @click="b.callback()">{{ b.label }}</button></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcPasswordField', () => ({
	default: { name: 'NcPasswordField', props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input type="password" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
}))

describe('EnableAppDialog', () => {
	it('asks for the password and hands it back to enable the app', async () => {
		const wrapper = mount(EnableAppDialog, { props: { open: true, appId: 'calendar', busy: false, error: '' } })

		await wrapper.find('input[type="password"]').setValue('secret')
		await wrapper.find('[data-label="Enable calendar"]').trigger('click')

		expect(wrapper.emitted('confirm')).toEqual([['secret']])
	})

	it('shows why enabling failed', () => {
		const wrapper = mount(EnableAppDialog, { props: { open: true, appId: 'calendar', busy: false, error: 'Wrong password' } })

		expect(wrapper.find('[data-testid="enable-app-error"]').text()).toBe('Wrong password')
	})
})
