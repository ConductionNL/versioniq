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
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', props: ['modelValue', 'disabled'], emits: ['update:modelValue'], template: '<label><slot /></label>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<select><slot /></select>' } }))

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

	it('offers and shows the security-fixes-only level', () => {
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'security', autoUpdateEnabled: true },
		})

		// The NcSelect stub declares no props, so the options arrive as an attribute.
		const options = wrapper.findComponent({ name: 'NcSelect' }).vm.$attrs.options as Array<{ id: string, label: string }>
		expect(options.map((option) => option.id)).toEqual(['none', 'patch', 'minor', 'all', 'security'])
		expect(options.at(-1)?.label).toBe('Security fixes only')
		expect(wrapper.find('[data-testid="policy-active-badge"]').text()).toContain('Security fixes only')
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

	// @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	it('offers the approval checkbox for an active policy and emits the choice', async () => {
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'patch', autoUpdateEnabled: true, requireApproval: false },
		})

		const checkbox = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(checkbox.exists()).toBe(true)
		expect(checkbox.props('modelValue')).toBe(false)

		await checkbox.vm.$emit('update:modelValue', true)

		expect(wrapper.emitted('approval')).toEqual([['openregister', true]])
	})

	it('shows the stored approval choice and hides it without a policy', () => {
		const active = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'minor', autoUpdateEnabled: true, requireApproval: true },
		})
		expect(active.findComponent({ name: 'NcCheckboxRadioSwitch' }).props('modelValue')).toBe(true)

		const off = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'none', autoUpdateEnabled: true, requireApproval: true },
		})
		expect(off.findComponent({ name: 'NcCheckboxRadioSwitch' }).exists()).toBe(false)
	})

	// audit-attribution-and-forwarding D2: who set the policy and when.
	// @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-changes-to-the-update-rules-are-audited
	it('shows who set an active policy and when', () => {
		const setAt = '2026-11-14T10:00:00+00:00'
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'minor', autoUpdateEnabled: true, setBy: 'alice', setAt },
		})

		const line = wrapper.find('[data-testid="policy-set-by"]')
		const expected = `Set by alice on ${new Date(setAt).toLocaleDateString()}`
		expect(line.text()).toBe(expected)
		expect(wrapper.find('[data-testid="policy-selector"]').attributes('title')).toBe(expected)
	})

	it('shows no setter for an inactive policy or one that names nobody', () => {
		const off = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'none', autoUpdateEnabled: true, setBy: 'alice', setAt: '2026-11-14T10:00:00+00:00' },
		})
		const nobody = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'patch', autoUpdateEnabled: true },
		})

		expect(off.find('[data-testid="policy-set-by"]').exists()).toBe(false)
		expect(off.find('[data-testid="policy-selector"]').attributes('title')).toBeUndefined()
		expect(nobody.find('[data-testid="policy-set-by"]').exists()).toBe(false)
	})

	it('names the setter alone when the time is missing', () => {
		const wrapper = shallowMount(PolicySelector, {
			props: { appId: 'openregister', level: 'all', autoUpdateEnabled: true, setBy: 'bob', setAt: '' },
		})

		expect(wrapper.find('[data-testid="policy-set-by"]').text()).toBe('Set by bob')
	})
})
