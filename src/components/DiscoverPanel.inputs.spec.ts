import { flushPromises, mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Input validation mirrors the API" and "Discover tab surfaces
// multi-source search": the debounce restarts on every keystroke, a query
// that drops below the minimum or a closed panel cancels the pending search,
// an over-long query is clamped, the source and installed-only filters
// re-search with their parameters, and a failed search shows its error.
// @spec openspec/specs/app-discovery/spec.md
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DiscoverPanel from './DiscoverPanel.vue'
import { ocsGet } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

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
		props: ['modelValue', 'reduce', 'options'],
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
}))

const mockedOcsGet = vi.mocked(ocsGet)
const EMPTY = { payload: { results: [], providers: [], errors: [] } }

async function typeQuery (wrapper: ReturnType<typeof mount>, value: string) {
	await wrapper.get('[data-testid="discover-search-input"]').setValue(value)
}

describe('DiscoverPanel inputs', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('restarts the debounce on every keystroke and searches once for the last query', async () => {
		mockedOcsGet.mockResolvedValue(EMPTY)
		const wrapper = mount(DiscoverPanel)

		await typeQuery(wrapper, 'open')
		await vi.advanceTimersByTimeAsync(300)
		await typeQuery(wrapper, 'openreg')
		await vi.advanceTimersByTimeAsync(300)
		expect(mockedOcsGet).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(100)
		expect(mockedOcsGet).toHaveBeenCalledTimes(1)
		expect(mockedOcsGet.mock.calls[0][1]).toMatchObject({ q: 'openreg', installedOnly: '0' })
	})

	it('cancels a pending search when the query drops below the minimum length', async () => {
		mockedOcsGet.mockResolvedValue(EMPTY)
		const wrapper = mount(DiscoverPanel)

		await typeQuery(wrapper, 'open')
		await vi.advanceTimersByTimeAsync(200)
		await typeQuery(wrapper, 'o')
		await vi.advanceTimersByTimeAsync(1000)

		expect(mockedOcsGet).not.toHaveBeenCalled()
		expect(wrapper.get('[data-testid="discover-validation-hint"]').text()).toBe('Type at least 2 characters to search.')
	})

	it('clamps a query longer than 100 characters and searches with the clamped text', async () => {
		mockedOcsGet.mockResolvedValue(EMPTY)
		const wrapper = mount(DiscoverPanel)

		await typeQuery(wrapper, 'a'.repeat(130))
		await vi.advanceTimersByTimeAsync(400)

		expect((wrapper.get('[data-testid="discover-search-input"]').element as HTMLInputElement).value).toHaveLength(100)
		expect(mockedOcsGet).toHaveBeenCalledTimes(1)
		expect(mockedOcsGet.mock.calls[0][1]).toMatchObject({ q: 'a'.repeat(100) })
	})

	it('sends nothing after the panel closes with a search still pending', async () => {
		mockedOcsGet.mockResolvedValue(EMPTY)
		const wrapper = mount(DiscoverPanel)

		await typeQuery(wrapper, 'openregister')
		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(1000)

		expect(mockedOcsGet).not.toHaveBeenCalled()
	})

	it('re-searches with the selected sources and the installed-only switch', async () => {
		mockedOcsGet.mockResolvedValue(EMPTY)
		const wrapper = mount(DiscoverPanel)
		await typeQuery(wrapper, 'register')
		await vi.advanceTimersByTimeAsync(400)

		const select = wrapper.getComponent({ name: 'NcSelect' })
		expect(select.props('reduce')({ id: 'appstore', label: 'Nextcloud App Store' })).toBe('appstore')
		select.vm.$emit('update:modelValue', ['appstore', 'github-private'])
		await vi.advanceTimersByTimeAsync(400)
		expect(mockedOcsGet.mock.calls[1][1]).toMatchObject({ q: 'register', sources: 'appstore,github-private', installedOnly: '0' })

		await wrapper.get('[data-testid="discover-installed-only"] input').setValue(true)
		await vi.advanceTimersByTimeAsync(400)
		expect(mockedOcsGet).toHaveBeenCalledTimes(3)
		expect(mockedOcsGet.mock.calls[2][1]).toMatchObject({ installedOnly: '1' })
	})

	it('shows the error of a search that failed, with no results', async () => {
		mockedOcsGet.mockRejectedValue(new Error('boom'))
		const wrapper = mount(DiscoverPanel)

		await typeQuery(wrapper, 'register')
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()

		expect(wrapper.find('[data-testid="discover-loading"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="discover-error"]').text()).toBe('boom')
		expect(wrapper.find('[data-testid="discover-hit"]').exists()).toBe(false)
	})
})
