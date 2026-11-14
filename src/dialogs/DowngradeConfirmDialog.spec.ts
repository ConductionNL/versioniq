// SPDX-License-Identifier: EUPL-1.2
// The downgrade confirmation names the transition, lists the migrations the
// target lacks (or summarises why it cannot), disables both answers while an
// install is busy, and treats dismissing as cancelling.
// @spec openspec/specs/migration-safety/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import DowngradeConfirmDialog from './DowngradeConfirmDialog.vue'

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

const props = {
	open: true,
	appId: 'calendar',
	fromVersion: '5.0.0',
	toVersion: '4.7.2',
	rangeText: '3 releases back',
	orphanedMigrations: ['Version5000Date20260101', 'Version5000Date20260202'] as string[] | null,
	busy: false,
}

describe('DowngradeConfirmDialog', () => {
	it('shows the app, the version transition, the range and each orphaned migration', () => {
		const wrapper = mount(DowngradeConfirmDialog, { props })

		expect(wrapper.text()).toContain('calendar')
		expect(wrapper.text()).toMatch(/5\.0\.0\s*→\s*4\.7\.2/)
		expect(wrapper.text()).toContain('Downgrade info: 3 releases back')
		expect(wrapper.findAll('li').map((li) => li.text())).toEqual(['Version5000Date20260101', 'Version5000Date20260202'])
	})

	it('shows a dash for an unknown installed version and hides an empty range', () => {
		const wrapper = mount(DowngradeConfirmDialog, { props: { ...props, fromVersion: '', rangeText: '' } })

		expect(wrapper.text()).toMatch(/—\s*→\s*4\.7\.2/)
		expect(wrapper.text()).not.toContain('Downgrade info:')
	})

	it('summarises when no migration differs', () => {
		const wrapper = mount(DowngradeConfirmDialog, { props: { ...props, orphanedMigrations: [] } })

		expect(wrapper.findAll('li')).toHaveLength(0)
		expect(wrapper.text()).toContain('No schema steps differ between the installed and target version.')
	})

	it('warns when the migrations could not be determined', () => {
		const wrapper = mount(DowngradeConfirmDialog, { props: { ...props, orphanedMigrations: null } })

		expect(wrapper.findAll('li')).toHaveLength(0)
		expect(wrapper.text()).toContain('Could not determine which database migrations differ between these versions.')
	})

	it.each([
		['Downgrade', true],
		['Cancel', false],
		['dismiss', false],
	])('%s closes the dialog and resolves %s', async (label, accept) => {
		const wrapper = mount(DowngradeConfirmDialog, { props })

		await wrapper.find(`[data-label="${label}"]`).trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('resolve')).toEqual([[accept]])
	})

	it('disables both answers while busy, and enables them again after', async () => {
		const wrapper = mount(DowngradeConfirmDialog, { props: { ...props, busy: true } })

		expect(wrapper.find('[data-label="Downgrade"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-label="Cancel"]').attributes('disabled')).toBeDefined()

		await wrapper.setProps({ busy: false })

		expect(wrapper.find('[data-label="Downgrade"]').attributes('disabled')).toBeUndefined()
		expect(wrapper.find('[data-label="Cancel"]').attributes('disabled')).toBeUndefined()
	})
	it('ignores a re-open and only resolves on an explicit close', async () => {
		const wrapper = mount(DowngradeConfirmDialog, { props })

		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', true)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('resolve')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

})
