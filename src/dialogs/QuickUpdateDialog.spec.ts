// SPDX-License-Identifier: EUPL-1.2
// The one-app confirmation shows the move and the release notes, and only
// the Update button asks the caller to install.
// @spec openspec/changes/install-one-click-updates/specs/version-management/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

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
import QuickUpdateDialog from './QuickUpdateDialog.vue'

const props = {
	open: true,
	appId: 'openregister',
	fromVersion: '2.3.0',
	toVersion: '2.4.1',
	entries: [{ version: '2.4.1', changelog: 'Faster search' }, { version: '2.4.0', changelog: '' }],
}

describe('QuickUpdateDialog', () => {
	it('shows the move and the release notes in between', () => {
		const wrapper = mount(QuickUpdateDialog, { props })

		expect(wrapper.find('[data-name]').attributes('data-name')).toBe('Update openregister')
		expect(wrapper.find('[data-testid="quick-update-move"]').text()).toBe('openregister moves from 2.3.0 to 2.4.1.')
		expect(wrapper.findAll('[data-testid="changelog-range-entry"]')).toHaveLength(2)
		expect(wrapper.text()).toContain('Faster search')
		expect(wrapper.find('[data-testid="quick-update-advisory"]').exists()).toBe(false)
	})

	it('names the advisory the safe version resolves', () => {
		const wrapper = mount(QuickUpdateDialog, { props: { ...props, toVersion: '2.3.4', advisoryId: 'GHSA-xxxx' } })

		expect(wrapper.find('[data-testid="quick-update-advisory"]').text()).toBe('This version resolves advisory GHSA-xxxx.')
	})

	it('asks for the install on Update', async () => {
		const wrapper = mount(QuickUpdateDialog, { props })

		await wrapper.find('[data-label="Update"]').trigger('click')

		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it.each(['Cancel', 'dismiss'])('installs nothing on %s', async (label) => {
		const wrapper = mount(QuickUpdateDialog, { props })

		await wrapper.find(`[data-label="${label}"]`).trigger('click')

		expect(wrapper.emitted('confirm')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('holds the Update button while the release notes load', () => {
		const wrapper = mount(QuickUpdateDialog, { props: { ...props, loading: true } })

		expect(wrapper.find('[data-label="Update"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="quick-update-loading"]').exists()).toBe(true)
	})
})
