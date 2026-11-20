// SPDX-License-Identifier: EUPL-1.2
// The app card says when an installed app lost the trust it was installed
// with: a revoked signing certificate (GET /api/apps) or a withdrawn
// installed version (GET /api/updates) (safety-withdrawn-and-revoked 2.3, 3.1).
// @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
// @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-a-withdrawn-installed-version-is-marked-and-never-reinstalled-by-accident
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
	{ id: 'badapp', label: 'Bad app', isCore: false, installedVersion: '2.0.0', state: 'enabled', certificateRevoked: true },
	{ id: 'hermiq', label: 'Hermiq', isCore: false, installedVersion: '1.4.0', state: 'enabled', certificateRevoked: false },
	{ id: 'calendar', label: 'Calendar', isCore: false, installedVersion: '5.2.1', state: 'enabled', certificateRevoked: false },
]

const entry = { newestVersion: null, newestCompatibleVersion: null, linesBehind: 0, updateAvailable: false, pinned: false, sourceId: 'appstore', error: null }
const updates = {
	badapp: { ...entry, installedVersion: '2.0.0', installedWithdrawn: false },
	hermiq: { ...entry, installedVersion: '1.4.0', installedWithdrawn: true },
	calendar: { ...entry, installedVersion: '5.2.1', installedWithdrawn: null },
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

const stubs = { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true }

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('App card trust badges', () => {
	it('shows "Certificate revoked" on the card of an app whose certificate was revoked, and only there', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		const wrapper = mount(App, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('[data-app-id="badapp"] [data-testid="certificate-revoked-badge"]').text()).toBe('Certificate revoked')
		expect(wrapper.findAll('[data-testid="certificate-revoked-badge"]')).toHaveLength(1)
	})

	it('shows "Version withdrawn by the publisher" on the card whose installed version was withdrawn, and only there', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		const wrapper = mount(App, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('[data-app-id="hermiq"] [data-testid="version-withdrawn-badge"]').text()).toBe('Version withdrawn by the publisher')
		expect(wrapper.findAll('[data-testid="version-withdrawn-badge"]')).toHaveLength(1)
	})
})
