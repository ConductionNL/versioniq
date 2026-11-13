// SPDX-License-Identifier: EUPL-1.2
// The checksum mismatch dialog shows both digests and resolves true only on
// the explicit accept button; cancel and dismiss both resolve false.
// @spec openspec/specs/external-sources/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ShaMismatchDialog from './ShaMismatchDialog.vue'

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

const props = { open: true, appId: 'deck', version: '1.15.0', expectedSha: 'aaa111', actualSha: 'bbb222' }

describe('ShaMismatchDialog', () => {
	it('names the release and shows the recorded and the downloaded digest', () => {
		const wrapper = mount(ShaMismatchDialog, { props })

		expect(wrapper.text()).toContain('deck 1.15.0 was previously installed with a different SHA-256 checksum.')
		const pairs = wrapper.findAll('dt').map((dt, i) => [dt.text(), wrapper.findAll('dd')[i].text()])
		expect(pairs).toEqual([['Recorded at first install', 'aaa111'], ['Just downloaded', 'bbb222']])
	})

	it.each([
		['Accept new checksum and install', true],
		['Cancel', false],
		['dismiss', false],
	])('%s closes the dialog and resolves %s', async (label, accept) => {
		const wrapper = mount(ShaMismatchDialog, { props })

		await wrapper.find(`[data-label="${label}"]`).trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('resolve')).toEqual([[accept]])
	})
})
