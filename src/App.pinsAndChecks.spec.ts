// SPDX-License-Identifier: EUPL-1.2
// Covers the Pinned filter (pin-list) and the check line saying whether the
// last sweep succeeded (adv-last-checked).
// @spec openspec/changes/inventory-pins-checks-and-pending/specs/version-pinning/spec.md
// @spec openspec/changes/inventory-pins-checks-and-pending/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number) => (count === 1 ? one : many),
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, _key: string, fallback: unknown) => fallback,
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: { name: 'NcDialog', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcPasswordField', () => ({ default: { name: 'NcPasswordField', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<div><slot /></div>' } }))

const apps = ['calendar', 'deck', 'mail', 'notes', 'tasks'].map((id) => ({ id, label: id[0].toUpperCase() + id.slice(1), isCore: false, installedVersion: '1.0.0', state: 'enabled' }))
const pins = ['deck', 'mail', 'tasks'].map((appId) => ({ appId, version: '1.0.0' }))

/**
 * Answers every OCS call the page makes on mount.
 *
 * @param extra Data per endpoint path fragment.
 */
function ocsFetch (extra: Record<string, unknown>) {
	return (input: RequestInfo | URL): Promise<Response> => {
		const url = String(input)
		let data: unknown = {}
		if (url.includes('/api/apps')) {
			data = { apps }
		} else if (url.includes('/api/pins')) {
			data = { pins }
		} else {
			for (const [fragment, value] of Object.entries(extra)) {
				if (url.includes(fragment)) {
					data = value
				}
			}
		}

		return Promise.resolve(new Response(JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } }), {
			status: 200,
			headers: { 'Content-Type': 'application/json' },
		}))
	}
}

const stubs = { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true }

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('Pinned filter', () => {
	it('counts the pinned apps, filters the list to them, and clears the filter', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch({})))
		const wrapper = mount(App, { global: { stubs } })
		await flushPromises()

		const filter = wrapper.find('[data-testid="pinned-filter"]')
		expect(filter.text()).toBe('Pinned 3')
		expect(wrapper.findAll('[data-app-id]')).toHaveLength(5)

		await filter.trigger('click')
		expect(wrapper.findAll('[data-app-id]').map((card) => card.attributes('data-app-id'))).toEqual(['deck', 'mail', 'tasks'])
		expect(filter.attributes('aria-pressed')).toBe('true')

		await filter.trigger('click')
		expect(wrapper.findAll('[data-app-id]')).toHaveLength(5)
	})
})

describe('Check line', () => {
	const now = Math.floor(Date.now() / 1000)

	it('says a failed advisory sweep could not check, and does not read as checked', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch({ '/api/advisories': { advisories: {}, checkedAt: now - 120, status: 'failed', errors: [] } })))
		const wrapper = mount(App, { global: { stubs } })
		await flushPromises()

		const line = wrapper.find('[data-testid="advisory-freshness"]').text()
		expect(line).toBe('Advisories could not be checked (2 min ago)')
		expect(line).not.toContain('checked 2')
	})

	it('names the apps a partial update sweep could not check', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch({ '/api/updates': { updates: {}, checkedAt: now - 120, status: 'partial', errors: ['deck'], maxLinesBehind: null } })))
		const wrapper = mount(App, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('[data-testid="updates-freshness"]').text()).toBe('Updates checked 2 min ago, except for Deck')
	})

	it('keeps the plain line for an ok sweep', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch({ '/api/updates': { updates: {}, checkedAt: now - 120, status: 'ok', errors: [], maxLinesBehind: null } })))
		const wrapper = mount(App, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('[data-testid="updates-freshness"]').text()).toBe('Updates checked 2 min ago')
	})
})
