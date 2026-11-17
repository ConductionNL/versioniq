import { flushPromises, mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers the public GitHub search switch on the Discover tab
// (sources-trust-audit-and-offline-feed 2.2):
// @spec openspec/specs/app-discovery/spec.md
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DiscoverPanel from './DiscoverPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

// Stub the @nextcloud/vue components with test-friendly, v-model-capable
// equivalents — the real components ship dist CSS side-effect imports Vitest
// can't resolve, and DiscoverPanel's own logic is what these specs target.
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: { name: 'NcButton', template: '<button><slot /></button>' },
}))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<select multiple />',
	},
}))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: {
		name: 'NcTextField',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}))

vi.mock('../ocs', () => ({
	ocsGet: vi.fn(),
	ocsWrite: vi.fn(),
}))

const mockedOcsGet = vi.mocked(ocsGet)
const mockedOcsWrite = vi.mocked(ocsWrite)

async function typeQuery (wrapper: ReturnType<typeof mount>, value: string) {
	await wrapper.get('[data-testid="discover-search-input"]').setValue(value)
}

describe('DiscoverPanel public GitHub search', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	// sources-trust-audit-and-offline-feed 2.2: "Public GitHub search is
	// switched on and off on the page".
	describe('the public GitHub search switch', () => {
		const answer = (enabled: boolean) => ({
			payload: {
				results: [],
				providers: [{ id: 'appstore', label: 'App Store', enabled: true }, { id: 'github-search', label: 'GitHub (public search)', enabled }],
				errors: [],
			},
		})

		beforeEach(() => {
			mockedOcsWrite.mockReset()
		})

		it('says the search is off and shows no switch before an answer names it', async () => {
			mockedOcsGet.mockResolvedValue(answer(false) as never)
			const wrapper = mount(DiscoverPanel)
			expect(wrapper.find('[data-testid="discover-github-search"]').exists()).toBe(false)

			await typeQuery(wrapper, 'openregister')
			await vi.advanceTimersByTimeAsync(400)
			await flushPromises()

			expect(wrapper.get('[data-testid="discover-github-search-state"]').text())
				.toBe('Search in public GitHub repositories is off. Private repositories are still searched with your tokens.')
		})

		it('turns the search on through the instance settings and searches again', async () => {
			mockedOcsGet.mockResolvedValueOnce(answer(false) as never).mockResolvedValueOnce(answer(true) as never)
			mockedOcsWrite.mockResolvedValue({ payload: { githubSearchEnabled: true }, error: null } as never)
			const wrapper = mount(DiscoverPanel)
			await typeQuery(wrapper, 'openregister')
			await vi.advanceTimersByTimeAsync(400)
			await flushPromises()

			await wrapper.get('[data-testid="discover-github-search"] input').setValue(true)
			await flushPromises()

			expect(mockedOcsWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/instance-settings', { githubSearchEnabled: 'true' })
			expect(wrapper.get('[data-testid="discover-github-search-state"]').text()).toBe('Search in public GitHub repositories is on.')
			await vi.advanceTimersByTimeAsync(400)
			expect(mockedOcsGet).toHaveBeenCalledTimes(2)
			expect(mockedOcsGet.mock.calls[1][1]).toMatchObject({ q: 'openregister' })
		})

		it('keeps the state and shows why when the change is refused', async () => {
			mockedOcsGet.mockResolvedValue(answer(false) as never)
			mockedOcsWrite.mockResolvedValue({ payload: {}, error: 'Password confirmation is required' } as never)
			const wrapper = mount(DiscoverPanel)
			await typeQuery(wrapper, 'openregister')
			await vi.advanceTimersByTimeAsync(400)
			await flushPromises()

			await wrapper.get('[data-testid="discover-github-search"] input').setValue(true)
			await flushPromises()

			expect(wrapper.get('[data-testid="discover-github-search-error"]').text()).toBe('Password confirmation is required')
			expect(wrapper.get('[data-testid="discover-github-search-state"]').text()).toContain('is off')
			await vi.advanceTimersByTimeAsync(400)
			expect(mockedOcsGet).toHaveBeenCalledTimes(1)
		})
	})
})
