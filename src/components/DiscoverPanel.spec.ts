import { flushPromises, mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Discover tab surfaces multi-source search" and "Hits route into
// existing flows":
// @spec openspec/specs/app-discovery/spec.md
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DiscoverPanel from './DiscoverPanel.vue'
import { ocsGet } from '../ocs.ts'

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
}))

const mockedOcsGet = vi.mocked(ocsGet)

async function typeQuery (wrapper: ReturnType<typeof mount>, value: string) {
	await wrapper.get('[data-testid="discover-search-input"]').setValue(value)
}

describe('DiscoverPanel', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('does not search before the 400ms debounce elapses', async () => {
		mockedOcsGet.mockResolvedValue({ payload: { results: [], providers: [], errors: [] } })
		const wrapper = mount(DiscoverPanel)

		await typeQuery(wrapper, 'openregister')
		expect(mockedOcsGet).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(400)
		expect(mockedOcsGet).toHaveBeenCalledTimes(1)
		expect(mockedOcsGet.mock.calls[0][1]).toMatchObject({ q: 'openregister' })
	})

	it('sends no request and shows a hint for a single-character query', async () => {
		const wrapper = mount(DiscoverPanel)

		await typeQuery(wrapper, 'o')
		await vi.advanceTimersByTimeAsync(1000)

		expect(mockedOcsGet).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="discover-validation-hint"]').exists()).toBe(true)
	})

	it('aborts the in-flight request when a new query supersedes it', async () => {
		const abortSpy = vi.spyOn(AbortController.prototype, 'abort')
		// First call never resolves within the test — simulates a slow request
		// still in flight when the next debounced search fires.
		mockedOcsGet.mockImplementationOnce(() => new Promise(() => {}))
		mockedOcsGet.mockResolvedValueOnce({ payload: { results: [], providers: [], errors: [] } })

		const wrapper = mount(DiscoverPanel)
		await typeQuery(wrapper, 'openregister')
		await vi.advanceTimersByTimeAsync(400)
		expect(mockedOcsGet).toHaveBeenCalledTimes(1)

		await typeQuery(wrapper, 'opencloud')
		await vi.advanceTimersByTimeAsync(400)

		expect(abortSpy).toHaveBeenCalled()
		expect(mockedOcsGet).toHaveBeenCalledTimes(2)
	})

	it('renders hit cards with source badges and the installed version', async () => {
		mockedOcsGet.mockResolvedValue({
			payload: {
				results: [{
					appId: 'openregister',
					name: 'Open Register',
					summary: 'A register app.',
					iconUrl: null,
					homepageUrl: null,
					installedVersion: '0.2.13',
					sourceCandidates: [
						{ providerId: 'appstore', sourceBinding: { kind: 'appstore' }, installable: true, installableReason: null },
						{ providerId: 'github-private', sourceBinding: { kind: 'github-release', owner: 'ConductionNL', repo: 'openregister' }, installable: true, installableReason: null },
					],
				}],
				providers: [
					{ id: 'appstore', label: 'Nextcloud App Store', enabled: true },
					{ id: 'github-private', label: 'GitHub (private)', enabled: true },
				],
				errors: [],
			},
		})

		const wrapper = mount(DiscoverPanel)
		await typeQuery(wrapper, 'register')
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()

		const hit = wrapper.get('[data-testid="discover-hit"]')
		expect(hit.text()).toContain('Open Register')
		expect(hit.text()).toContain('openregister')
		expect(wrapper.get('[data-testid="discover-installed-version"]').text()).toContain('0.2.13')
		expect(wrapper.findAll('[data-testid="discover-source-badge"]')).toHaveLength(2)
	})

	it('keeps surviving results visible and shows a dismissible note on partial provider failure', async () => {
		mockedOcsGet.mockResolvedValue({
			payload: {
				results: [{
					appId: 'openregister',
					name: 'Open Register',
					summary: '',
					iconUrl: null,
					homepageUrl: null,
					installedVersion: null,
					sourceCandidates: [{ providerId: 'appstore', sourceBinding: { kind: 'appstore' }, installable: true, installableReason: null }],
				}],
				providers: [{ id: 'appstore', label: 'Nextcloud App Store', enabled: true }, { id: 'github-private', label: 'GitHub (private)', enabled: true }],
				errors: [{ providerId: 'github-private', message: 'rate limit exceeded' }],
			},
		})

		const wrapper = mount(DiscoverPanel)
		await typeQuery(wrapper, 'register')
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()

		expect(wrapper.findAll('[data-testid="discover-hit"]')).toHaveLength(1)
		const note = wrapper.get('[data-testid="discover-provider-error"]')
		expect(note.text()).toContain('GitHub (private)')
		expect(note.text()).toContain('rate limit exceeded')

		await wrapper.get('[data-testid="discover-dismiss-provider-error"]').trigger('click')
		expect(wrapper.find('[data-testid="discover-provider-error"]').exists()).toBe(false)
	})

	it('emits openApp for an installed hit', async () => {
		mockedOcsGet.mockResolvedValue({
			payload: {
				results: [{
					appId: 'openregister',
					name: 'Open Register',
					summary: '',
					iconUrl: null,
					homepageUrl: null,
					installedVersion: '0.2.13',
					sourceCandidates: [{ providerId: 'appstore', sourceBinding: { kind: 'appstore' }, installable: true, installableReason: null }],
				}],
				providers: [],
				errors: [],
			},
		})

		const wrapper = mount(DiscoverPanel)
		await typeQuery(wrapper, 'register')
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()

		await wrapper.get('[data-testid="discover-open-app"]').trigger('click')

		expect(wrapper.emitted('openApp')).toEqual([['openregister']])
	})

	it('emits prefillBind with the installable candidate for a not-installed hit', async () => {
		mockedOcsGet.mockResolvedValue({
			payload: {
				results: [{
					appId: 'hermiq',
					name: 'Hermiq',
					summary: '',
					iconUrl: null,
					homepageUrl: null,
					installedVersion: null,
					sourceCandidates: [{
						providerId: 'github-private',
						sourceBinding: { kind: 'github-release', owner: 'ConductionNL', repo: 'hermiq', forge: 'github' },
						installable: true,
						installableReason: null,
					}],
				}],
				providers: [],
				errors: [],
			},
		})

		const wrapper = mount(DiscoverPanel)
		await typeQuery(wrapper, 'hermiq')
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()

		await wrapper.get('[data-testid="discover-install"]').trigger('click')

		expect(wrapper.emitted('prefillBind')).toEqual([[{
			appId: 'hermiq',
			forge: 'github',
			owner: 'ConductionNL',
			repo: 'hermiq',
			assetPattern: '*.tar.gz',
		}]])
	})

	it('shows the not-trusted reason and emits openTrusted for a non-installable hit', async () => {
		mockedOcsGet.mockResolvedValue({
			payload: {
				results: [{
					appId: 'someapp',
					name: 'Some App',
					summary: '',
					iconUrl: null,
					homepageUrl: null,
					installedVersion: null,
					sourceCandidates: [{
						providerId: 'github-search',
						sourceBinding: { kind: 'github-release', owner: 'OtherOrg', repo: 'someapp' },
						installable: false,
						installableReason: 'Add `OtherOrg/*` to the trusted-source allowlist to install this app.',
					}],
				}],
				providers: [],
				errors: [],
			},
		})

		const wrapper = mount(DiscoverPanel)
		await typeQuery(wrapper, 'someapp')
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()

		const reason = wrapper.get('[data-testid="discover-not-installable"]')
		expect(reason.text()).toContain('OtherOrg/*')
		expect(wrapper.find('[data-testid="discover-install"]').exists()).toBe(false)

		await wrapper.get('[data-testid="discover-open-trusted"]').trigger('click')
		expect(wrapper.emitted('openTrusted')).toEqual([[]])
	})
})
