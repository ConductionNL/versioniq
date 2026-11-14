import { shallowMount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Per-app update policy" and "Global kill switch and window"
// ("Kill switch inert-but-stored"):
// @spec openspec/specs/auto-update-policies/spec.md
import { describe, expect, it, vi } from 'vitest'
import PolicySelector from './PolicySelector.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<select><slot /></select>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', props: ['modelValue'], emits: ['update:modelValue'], template: '<label :data-checked="modelValue" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></label>' } }))

describe('PolicySelector', () => {
	it('renders the selector without an active badge when the level is none', () => {
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'none', autoUpdateEnabled: true },
		})

		expect(wrapper.find('[data-testid="policy-selector"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="policy-active-badge"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="policy-disabled-hint"]').exists()).toBe(false)
	})

	it('shows an active badge when a policy level is set and automation is enabled', () => {
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'patch', autoUpdateEnabled: true },
		})

		const badge = wrapper.find('[data-testid="policy-active-badge"]')
		expect(badge.exists()).toBe(true)
		expect(badge.text()).toContain('Patch')
		expect(wrapper.find('[data-testid="policy-disabled-hint"]').exists()).toBe(false)
	})

	it('shows the automation-disabled hint when a policy is active but the kill switch is off', () => {
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'minor', autoUpdateEnabled: false },
		})

		expect(wrapper.find('[data-testid="policy-active-badge"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="policy-disabled-hint"]').exists()).toBe(true)
	})

	it('emits change with the appId and the newly selected level', async () => {
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'none', autoUpdateEnabled: true },
		})

		const select = wrapper.findComponent({ name: 'NcSelect' })
		await select.vm.$emit('update:modelValue', { id: 'all', label: 'All' })

		expect(wrapper.emitted('change')).toEqual([['openregister', 'all']])
	})

	// #438: a failed automatic update blocked that version for good and no
	// page showed it or offered a way out.
	it('lists blocked versions with a retry action that names the version', async () => {
		const wrapper = shallowMount(PolicySelector, {
			props: {
				appId: 'openregister',
				level: 'patch',
				autoUpdateEnabled: true,
				blockedVersions: [{ version: '2.3.4', at: '2026-07-22T02:00:00+00:00' }],
			},
			global: { stubs: { NcButton: false } },
		})

		const blocked = wrapper.find('[data-testid="policy-blocked-versions"]')
		expect(blocked.exists()).toBe(true)
		expect(blocked.text()).toContain('2.3.4')

		await wrapper.find('[data-testid="policy-retry-2.3.4"]').trigger('click')
		expect(wrapper.emitted('retry')).toEqual([['openregister', '2.3.4']])
	})

	it('shows no blocked list when nothing is blocked', () => {
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'patch', autoUpdateEnabled: true },
		})

		expect(wrapper.find('[data-testid="policy-blocked-versions"]').exists()).toBe(false)
	})

	it('says automation is disabled without an em-dash', () => {
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'minor', autoUpdateEnabled: false },
		})

		expect(wrapper.find('[data-testid="policy-disabled-hint"]').text()).not.toContain('\u2014')
	})

	// pinning-ranges-and-skips: "Also hold manual installs to this level"
	// @spec openspec/changes/pinning-ranges-and-skips/specs/auto-update-policies/spec.md#requirement-a-policy-can-hold-manual-installs-to-its-level
	it('offers the hold-manual switch for patch and minor only, with help text that leaves the platform updater out', () => {
		for (const [level, shown] of [['none', false], ['patch', true], ['minor', true], ['all', false]] as const) {
			const wrapper = shallowMount(PolicySelector, { props: { appId: 'openregister', level, autoUpdateEnabled: true }, global: { renderStubDefaultSlot: true } })
			expect(wrapper.find('[data-testid="policy-hold-manual"]').exists()).toBe(shown)
			if (shown) {
				expect(wrapper.text()).toContain('Nextcloud\'s own updater is not held')
			}
		}
	})

	it('saves the switch by emitting it with the app', async () => {
		const wrapper = shallowMount(PolicySelector, { props: { appId: 'openregister', level: 'patch', autoUpdateEnabled: true, holdManual: false } })

		await wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)

		expect(wrapper.emitted('holdManual')).toEqual([['openregister', true]])
		expect(wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).props('modelValue')).toBe(false)
	})
})
