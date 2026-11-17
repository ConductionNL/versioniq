// SPDX-License-Identifier: EUPL-1.2
// The app card shows the life cycle signal and the replace action the
// pending-updates snapshot carries (inventory-lifecycle-signals).
// @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
// @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
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
// The page's own template is under test; the library components are not.
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: { name: 'NcDialog', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcPasswordField', () => ({ default: { name: 'NcPasswordField', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<div><slot /></div>' } }))

const apps = [
	{ id: 'oldname', label: 'Old name', isCore: false, installedVersion: '1.2.0', state: 'enabled' },
	{ id: 'calendar', label: 'Calendar', isCore: false, installedVersion: '5.2.1', state: 'enabled' },
]

const updates = {
	oldname: {
		installedVersion: '1.2.0', newestVersion: null, newestCompatibleVersion: null, linesBehind: 0, updateAvailable: false, pinned: false, sourceId: 'appstore', error: null,
		lifecycle: { removedFromStore: true, archived: null, noReleaseFor12Months: true, lastReleasedAt: '2024-01-01T00:00:00Z' },
		successor: { from: 'oldname', to: 'newname', source: 'appstore', custom: false, version: '1.0.0' },
	},
	calendar: {
		installedVersion: '5.2.1', newestVersion: '5.2.1', newestCompatibleVersion: '5.2.1', linesBehind: 0, updateAvailable: false, pinned: false, sourceId: 'appstore', error: null,
		lifecycle: { removedFromStore: false, archived: false, noReleaseFor12Months: false, lastReleasedAt: '2026-10-01T00:00:00Z' },
	},
}

/**
 * Answers every OCS call the page makes on mount: the app list and the snapshot carry data.
 *
 * @param input The requested URL.
 */
function ocsFetch (input: RequestInfo | URL): Promise<Response> {
	const url = String(input)
	const data = url.includes('/api/apps') ? { apps } : (url.includes('/api/updates') ? { updates, checkedAt: 1, status: 'ok', errors: [], maxLinesBehind: null } : {})

	return Promise.resolve(new Response(JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } }), {
		status: 200,
		headers: { 'Content-Type': 'application/json' },
	}))
}

const stubs = { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true, ReplaceAppDialog: { name: 'ReplaceAppDialog', props: ['appId', 'offer'], template: '<div data-testid="replace-dialog">{{ appId }}>{{ offer.to }}</div>' } }

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('App card life cycle', () => {
	it('shows the strongest signal on the card, and none for an app without one', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		const wrapper = mount(App, { global: { stubs } })
		await flushPromises()

		const badge = wrapper.find('[data-app-id="oldname"] [data-testid="lifecycle-badge"]')
		expect(badge.text()).toBe('Removed from the App Store')
		expect(badge.attributes('title')).toBe('No release in a year')
		expect(wrapper.find('[data-app-id="calendar"] [data-testid="lifecycle-badge"]').exists()).toBe(false)
	})

	it('offers the successor on the old app\'s card and opens the replace dialog for it', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		const wrapper = mount(App, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('[data-app-id="calendar"] [data-testid="replace-successor-button"]').exists()).toBe(false)
		const button = wrapper.find('[data-app-id="oldname"] [data-testid="replace-successor-button"]')
		expect(button.text()).toBe('Replace with newname')

		await button.trigger('click')

		expect(wrapper.find('[data-testid="replace-dialog"]').text()).toBe('oldname>newname')
	})
})
