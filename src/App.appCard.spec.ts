// SPDX-License-Identifier: EUPL-1.2
// Covers "each card MUST show: app name, current version, icon, and summary" (#477).
// @spec openspec/specs/version-management/spec.md
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
	{ id: 'calendar', label: 'Calendar', isCore: false, installedVersion: '5.2.1', state: 'enabled' },
	{ id: 'notyet', label: 'Not yet', isCore: false, installedVersion: null, state: 'notInstalled' },
]

/**
 * Answers every OCS call the page makes on mount; only the app list carries data.
 *
 * @param input The requested URL.
 */
function ocsFetch (input: RequestInfo | URL): Promise<Response> {
	const url = String(input)
	const data = url.includes('/api/apps') ? { apps } : {}

	return Promise.resolve(new Response(JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } }), {
		status: 200,
		headers: { 'Content-Type': 'application/json' },
	}))
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('App card', () => {
	it('shows the installed version on the card of an installed app, and none on an app that is not installed', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		// Panels on other tabs fetch their own data; they are not under test.
		const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
		await flushPromises()

		const calendar = wrapper.find('[data-app-id="calendar"]')
		expect(calendar.exists()).toBe(true)
		expect(calendar.find('[data-testid="app-installed-version"]').text()).toBe('Installed 5.2.1')

		const notYet = wrapper.find('[data-app-id="notyet"]')
		expect(notYet.find('[data-testid="app-installed-version"]').exists()).toBe(false)
	})
})
