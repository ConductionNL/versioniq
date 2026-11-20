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

/**
 * A last-known-good record with the version before it.
 *
 * @param version The last good version.
 * @param previous The version that ran before it, or null.
 */
function lkg (version: string, previous: string | null) {
	return {
		version,
		recordedAt: '2026-11-14T10:00:00+00:00',
		sourceId: 'appstore',
		previous: previous === null ? null : { version: previous, recordedAt: '2026-11-01T10:00:00+00:00', sourceId: 'appstore' },
	}
}

const apps = [
	{ id: 'calendar', label: 'Calendar', isCore: false, installedVersion: '5.2.1', state: 'enabled', installedKnownIssues: [{ text: 'Export to CSV times out', workaround: 'Export per schema' }, { text: 'Second' }] },
	{ id: 'notyet', label: 'Not yet', isCore: false, installedVersion: null, state: 'notInstalled' },
	// The last good version is installed: one target, the version before it.
	{ id: 'dossiq', label: 'Dossiq', isCore: false, installedVersion: '0.4.46', state: 'enabled', lkg: lkg('0.4.46', '0.4.44') },
	// 0.4.48 was put in place outside Versioniq: both targets.
	{ id: 'drifted', label: 'Drifted', isCore: false, installedVersion: '0.4.48', state: 'enabled', lkg: lkg('0.4.46', '0.4.44') },
]

let appsAnswer: Array<Record<string, unknown>> = apps
let disabledByUpdate: unknown = []
let advisoriesAnswer: Record<string, unknown> = {}

/**
 * Answers every OCS call the page makes on mount; only the app list carries data.
 *
 * @param input The requested URL.
 */
function ocsFetch (input: RequestInfo | URL): Promise<Response> {
	const url = String(input)
	const data = url.includes('/api/apps') ? { apps: appsAnswer, disabledByUpdate } : (url.includes('/api/advisories') ? advisoriesAnswer : {})

	return Promise.resolve(new Response(JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } }), {
		status: 200,
		headers: { 'Content-Type': 'application/json' },
	}))
}

afterEach(() => {
	vi.unstubAllGlobals()
	appsAnswer = apps
	advisoriesAnswer = {}
	disabledByUpdate = []
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

	it('offers the version before the last install, and the last good version when it differs from both', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
		await flushPromises()

		const labels = (id: string) => wrapper.find(`[data-app-id="${id}"]`).findAll('[data-testid="app-rollback-button"]').map((button) => button.text())
		expect(labels('dossiq')).toEqual(['Roll back to 0.4.44'])
		expect(labels('drifted')).toEqual(['Roll back to 0.4.44', 'Roll back to the last good version (0.4.46)'])
		expect(labels('calendar')).toEqual([])
	})

	it('says a known issue is recorded for the installed version, with each workaround in its tooltip (releases-version-facts D4)', async () => {
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
		await flushPromises()

		const notice = wrapper.find('[data-app-id="calendar"] [data-testid="app-known-issue"]')
		expect(notice.text()).toBe('Known issue in the installed version')
		expect(notice.attributes('title')).toBe('Export to CSV times out Workaround: Export per schema\nSecond')
		expect(wrapper.find('[data-app-id="dossiq"] [data-testid="app-known-issue"]').exists()).toBe(false)
	})

	// @spec openspec/changes/audit-external-changes
	it('says an update left an app disabled, with Enable only when it runs on this server', async () => {
		appsAnswer = [
			{ id: 'forms', label: 'Forms', isCore: false, installedVersion: '4.3.0', state: 'disabled' },
			{ id: 'hermiq', label: 'Hermiq', isCore: false, installedVersion: '1.4.0', state: 'disabled' },
			{ id: 'notes', label: 'Notes', isCore: false, installedVersion: '1.0.0', state: 'disabled' },
		]
		disabledByUpdate = {
			forms: { toVersion: 'Nextcloud 34', compatible: true, compatibleVersion: null },
			hermiq: { toVersion: 'Nextcloud 34', compatible: false, compatibleVersion: '1.5.0' },
		}
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
		await flushPromises()

		const forms = wrapper.find('[data-app-id="forms"]')
		expect(forms.find('[data-testid="app-left-disabled"]').text()).toBe('Disabled by the update to Nextcloud 34')
		expect(forms.find('[data-testid="app-enable-button"]').exists()).toBe(true)

		const hermiq = wrapper.find('[data-app-id="hermiq"]')
		expect(hermiq.find('[data-testid="app-left-disabled"]').text()).toBe('Disabled by the update to Nextcloud 34. It does not run on this server; version 1.5.0 does.')
		expect(hermiq.find('[data-testid="app-enable-button"]').exists()).toBe(false)

		// Disabled on purpose: no notice, and Enable as before.
		const notes = wrapper.find('[data-app-id="notes"]')
		expect(notes.find('[data-testid="app-left-disabled"]').exists()).toBe(false)
		expect(notes.find('[data-testid="app-enable-button"]').exists()).toBe(true)
	})

	// @spec openspec/changes/advisories-bundled-libraries
	it('badges an app whose bundled libraries have an advisory, linking to the Bundled libraries block', async () => {
		advisoriesAnswer = {
			advisories: {},
			checkedAt: null,
			libraries: {
				calendar: { manifest: 'vendor/composer/installed.json', checked: 4, skipped: 0, error: null, findings: [{ package: 'vendor/library', version: '2.4.3', id: 'GHSA-xxxx', cveIds: [], severity: 'high', summary: '', fixedIn: '2.4.5', url: '', malicious: false }] },
				notyet: { manifest: 'vendor/composer/installed.json', checked: 2, skipped: 0, error: null, findings: [] },
			},
			librariesCheckedAt: 1700000000,
		}
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
		await flushPromises()

		const badge = wrapper.find('[data-app-id="calendar"] [data-testid="library-advisory-badge"]')
		expect(badge.text()).toBe('Library advisory')
		expect(badge.attributes('href')).toBe('#section-bundled-libraries')
		expect(wrapper.find('[data-app-id="notyet"] [data-testid="library-advisory-badge"]').exists()).toBe(false)
		// Library findings stay apart from the app advisories.
		expect((wrapper.vm as unknown as Record<string, unknown>).advisories).toEqual({})
		expect((wrapper.vm as unknown as Record<string, unknown>).librariesCheckedAt).toBe(1700000000)
	})

	it('reads an empty library snapshot, sent as a list, as no checks', async () => {
		advisoriesAnswer = { advisories: {}, checkedAt: null, libraries: [], librariesCheckedAt: null }
		vi.stubGlobal('fetch', vi.fn(ocsFetch))
		const wrapper = mount(App, { global: { stubs: { AdvisoriesPanel: true, CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
		await flushPromises()

		expect((wrapper.vm as unknown as Record<string, unknown>).libraryChecks).toEqual({})
		expect(wrapper.find('[data-testid="library-advisory-badge"]').exists()).toBe(false)
	})
})
