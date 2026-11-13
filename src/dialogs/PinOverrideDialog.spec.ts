// SPDX-License-Identifier: EUPL-1.2
// The pin override dialog only decides which override the caller sends:
// every button and the dismiss path close the dialog and resolve one choice.
// @spec openspec/specs/version-pinning/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import PinOverrideDialog from './PinOverrideDialog.vue'

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

const props = { open: true, appId: 'mail', pinnedVersion: '3.7.0', targetVersion: '4.0.1' }

describe('PinOverrideDialog', () => {
	it('names the app, the pinned and the target version', () => {
		const wrapper = mount(PinOverrideDialog, { props })

		expect(wrapper.find('[data-name]').attributes('data-name')).toBe('mail is pinned')
		expect(wrapper.text()).toContain('mail is pinned to version 3.7.0.')
		expect(wrapper.text()).toContain('will install 4.0.1 and move the pin there')
	})

	it.each([
		['Move pin and install', 'repin'],
		['Unpin and install', 'unpin'],
		['Cancel', 'cancel'],
		['dismiss', 'cancel'],
	])('%s closes the dialog and resolves %s', async (label, choice) => {
		const wrapper = mount(PinOverrideDialog, { props })

		await wrapper.find(`[data-label="${label}"]`).trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('resolve')).toEqual([[choice]])
	})

	it('renders nothing while closed', () => {
		const wrapper = mount(PinOverrideDialog, { props: { ...props, open: false } })

		expect(wrapper.find('[data-name]').exists()).toBe(false)
	})
})
