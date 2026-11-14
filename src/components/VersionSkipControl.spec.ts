// SPDX-License-Identifier: EUPL-1.2
// A version's skip state in the version picker.
// @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import VersionSkipControl from './VersionSkipControl.vue'

const api = vi.hoisted(() => ({ skipVersion: vi.fn(), unskipVersion: vi.fn() }))

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('../utils/skips.ts', () => api)
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' } }))

function mountControl (skip: Record<string, string> | null = null) {
  return mount(VersionSkipControl, {
	props: { appId: 'openregister', version: '2.3.4', skip },
})
}

describe('VersionSkipControl', () => {
	beforeEach(() => {
		api.skipVersion.mockReset().mockResolvedValue(undefined)
		api.unskipVersion.mockReset().mockResolvedValue(undefined)
	})

	it('skips with the reason given', async () => {
		const wrapper = mountControl()

		await wrapper.find('[data-testid="version-skip"]').trigger('click')
		await wrapper.find('[data-testid="version-skip-reason"]').setValue('breaks the import')
		await wrapper.find('[data-testid="version-skip-confirm"]').trigger('click')
		await flushPromises()

		expect(api.skipVersion).toHaveBeenCalledWith('openregister', '2.3.4', 'breaks the import')
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('marks a skipped version with its reason and unskips it', async () => {
		const wrapper = mountControl({ version: '2.3.4', skippedBy: 'admin', skippedAt: 'T', reason: 'breaks the import' })

		expect(wrapper.find('[data-testid="version-skipped-badge"]').text()).toContain('breaks the import')
		await wrapper.find('[data-testid="version-unskip"]').trigger('click')
		await flushPromises()

		expect(api.unskipVersion).toHaveBeenCalledWith('openregister', '2.3.4')
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('reports a refused skip', async () => {
		api.skipVersion.mockRejectedValue(new Error('Forbidden'))
		const wrapper = mountControl()

		await wrapper.find('[data-testid="version-skip"]').trigger('click')
		await wrapper.find('[data-testid="version-skip-confirm"]').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('error')).toEqual([['Forbidden']])
		expect(wrapper.emitted('changed')).toBeUndefined()
	})
})
