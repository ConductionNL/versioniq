// SPDX-License-Identifier: EUPL-1.2
// Update all lists the apps with an update, leaves pinned apps unticked with
// the reason, starts the run with the ticked ones and shows each outcome.
// @spec openspec/changes/install-one-click-updates/specs/version-management/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace('%n', String(count)).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
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
import type { BatchRow } from '../utils/updateBatch.ts'

import UpdateAllDialog from './UpdateAllDialog.vue'

const candidates = [
	{ appId: 'calendar', fromVersion: '4.0.0', toVersion: '4.1.0', pinned: false, selected: true },
	{ appId: 'hermiq', fromVersion: '1.0.0', toVersion: '1.2.0', pinned: true, pinnedVersion: '1.0.0', selected: false },
	{ appId: 'openregister', fromVersion: '2.3.0', toVersion: '2.4.1', pinned: false, selected: true },
]

const props = { open: true, candidates, rows: {}, running: false }

describe('UpdateAllDialog', () => {
	it('ticks every app except the pinned one, which says why', () => {
		const wrapper = mount(UpdateAllDialog, { props })

		const boxes = wrapper.findAll('input[type="checkbox"]').map((box) => (box.element as HTMLInputElement).checked)
		expect(boxes).toEqual([true, false, true])
		expect(wrapper.find('[data-testid="update-all-row-hermiq"]').text()).toContain('Pinned to 1.0.0')
		expect(wrapper.find('[data-label="Update 2 apps"]').exists()).toBe(true)
	})

	it('starts the run with the ticked apps only', async () => {
		const wrapper = mount(UpdateAllDialog, { props })

		await wrapper.findAll('input[type="checkbox"]')[0]?.setValue(false)
		await wrapper.find('[data-label="Update 1 app"]').trigger('click')

		expect(wrapper.emitted('start')).toEqual([[[{ ...candidates[2], selected: true }]]])
	})

	it('shows each outcome as it lands', () => {
		const rows: Record<string, BatchRow> = {
			calendar: { appId: 'calendar', toVersion: '4.1.0', state: 'failed', category: 'checksum', message: 'Checksum mismatch' },
			openregister: { appId: 'openregister', toVersion: '2.4.1', state: 'updated' },
		}
		const wrapper = mount(UpdateAllDialog, { props: { ...props, rows } })

		expect(wrapper.find('[data-testid="update-all-row-calendar"]').text()).toContain('Failed (checksum): Checksum mismatch')
		expect(wrapper.find('[data-testid="update-all-row-openregister"]').text()).toContain('Updated to 2.4.1')
		expect(wrapper.find('[data-label="Update 2 apps"]').exists()).toBe(false)
	})

	it('closing a running batch asks it to stop after the current install and says so', async () => {
		const wrapper = mount(UpdateAllDialog, { props: { ...props, running: true, rows: { calendar: { appId: 'calendar', toVersion: '4.1.0', state: 'running' } } } })

		await wrapper.find('[data-label="dismiss"]').trigger('click')

		expect(wrapper.emitted('stop')).toHaveLength(1)
		expect(wrapper.emitted('update:open')).toBeUndefined()
		expect(wrapper.find('[data-testid="update-all-stopping"]').text()).toBe('The run stops after the current install.')
	})

	it('closes when no run is going', async () => {
		const wrapper = mount(UpdateAllDialog, { props })

		await wrapper.find('[data-label="Close"]').trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('stop')).toBeUndefined()
	})
})
