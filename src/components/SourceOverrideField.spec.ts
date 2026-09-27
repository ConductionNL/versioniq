// SPDX-License-Identifier: EUPL-1.2
// Covers "One-off source override on the page" (#438 item 6):
// @spec openspec/specs/external-sources/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SourceOverrideField from './SourceOverrideField.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))

describe('SourceOverrideField', () => {
	it('applies a source for this app only, without touching the binding', async () => {
		const wrapper = mount(SourceOverrideField, { props: { modelValue: '', boundSourceId: 'appstore' } })

		await wrapper.find('input').setValue('github:acme/app')
		await wrapper.find('[data-testid="source-override-apply"]').trigger('click')

		expect(wrapper.emitted('apply')).toEqual([['github:acme/app']])
		expect(wrapper.text()).toContain('The binding stays appstore')
	})

	it('clears an active override', async () => {
		const wrapper = mount(SourceOverrideField, { props: { modelValue: 'github:acme/app', boundSourceId: null } })

		await wrapper.find('[data-testid="source-override-clear"]').trigger('click')

		expect(wrapper.emitted('apply')).toEqual([['']])
	})
})
