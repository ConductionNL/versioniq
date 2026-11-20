// SPDX-License-Identifier: EUPL-1.2
// Skip and Unskip on an app card's update badge.
// @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import UpdateBadgeSkip from './UpdateBadgeSkip.vue'

const api = vi.hoisted(() => ({ skipVersion: vi.fn(), unskipVersion: vi.fn(), fetchSkips: vi.fn() }))

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('../utils/skips.ts', () => api)
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' } }))

const SKIPPED = { version: '1.2.0', skippedBy: 'admin', skippedAt: 'T', reason: 'breaks the import' }

describe('UpdateBadgeSkip', () => {
	beforeEach(() => {
		api.skipVersion.mockReset().mockResolvedValue(undefined)
		api.unskipVersion.mockReset().mockResolvedValue(undefined)
		api.fetchSkips.mockReset().mockResolvedValue({ '1.2.0': SKIPPED })
	})

	it('skips the offered version, then shows it skipped with Unskip', async () => {
		const wrapper = mount(UpdateBadgeSkip, { props: { appId: 'fixtureapp', version: '1.2.0' } })
		expect(api.fetchSkips).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="version-skip"]').trigger('click')
		await wrapper.find('[data-testid="version-skip-reason"]').setValue('breaks the import')
		await wrapper.find('[data-testid="version-skip-confirm"]').trigger('click')
		await flushPromises()

		expect(api.skipVersion).toHaveBeenCalledWith('fixtureapp', '1.2.0', 'breaks the import')
		expect(api.fetchSkips).toHaveBeenCalledWith('fixtureapp')
		expect(wrapper.find('[data-testid="version-skipped-badge"]').text()).toContain('breaks the import')
		expect(wrapper.find('[data-testid="version-unskip"]').exists()).toBe(true)
		expect(wrapper.emitted('changed')).toEqual([['fixtureapp']])
	})

	it('unskips from the badge and offers Skip again', async () => {
		const wrapper = mount(UpdateBadgeSkip, { props: { appId: 'fixtureapp', version: '1.2.0' } })
		await wrapper.find('[data-testid="version-skip"]').trigger('click')
		await wrapper.find('[data-testid="version-skip-confirm"]').trigger('click')
		await flushPromises()
		api.fetchSkips.mockResolvedValue({})

		await wrapper.find('[data-testid="version-unskip"]').trigger('click')
		await flushPromises()

		expect(api.unskipVersion).toHaveBeenCalledWith('fixtureapp', '1.2.0')
		expect(wrapper.find('[data-testid="version-skip"]').exists()).toBe(true)
	})

	it('starts fresh when the sweep offers another version', async () => {
		const wrapper = mount(UpdateBadgeSkip, { props: { appId: 'fixtureapp', version: '1.2.0' } })
		await wrapper.find('[data-testid="version-skip"]').trigger('click')
		await wrapper.find('[data-testid="version-skip-confirm"]').trigger('click')
		await flushPromises()

		await wrapper.setProps({ version: '1.1.0' })

		expect(wrapper.find('[data-testid="version-skipped-badge"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="version-skip"]').exists()).toBe(true)
	})

	it('passes a refused skip on to the page', async () => {
		api.skipVersion.mockRejectedValue(new Error('Forbidden'))
		const wrapper = mount(UpdateBadgeSkip, { props: { appId: 'fixtureapp', version: '1.2.0' } })

		await wrapper.find('[data-testid="version-skip"]').trigger('click')
		await wrapper.find('[data-testid="version-skip-confirm"]').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('error')).toEqual([['Forbidden']])
		expect(wrapper.emitted('changed')).toBeUndefined()
	})
})
