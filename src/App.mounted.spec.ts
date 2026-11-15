// SPDX-License-Identifier: EUPL-1.2
// Pins what the page does when it mounts: the stored mode flags and the order of its first requests.
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
 * Answers every OCS call the page makes on mount with an empty payload.
 *
 * @param input The requested URL.
 */
function ocsFetch (input: RequestInfo | URL): Promise<Response> {
	const data = String(input).includes('/api/apps') ? { apps: [] } : {}

	return Promise.resolve(new Response(JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } }), {
		status: 200,
		headers: { 'Content-Type': 'application/json' },
	}))
}

const stubs = { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true }

/**
 * Mount the page and wait for its first requests.
 */
async function mountPage (): Promise<{ vm: Record<string, unknown>, paths: string[] }> {
	const fetchMock = vi.fn(ocsFetch)
	vi.stubGlobal('fetch', fetchMock)
	const wrapper = mount(App, { global: { stubs } })
	await flushPromises()
	const paths = fetchMock.mock.calls.map(([input]) => new URL(String(input), 'http://localhost').pathname.replace('/ocs/v2.php/apps/versioniq', ''))

	return { vm: wrapper.vm as unknown as Record<string, unknown>, paths }
}

afterEach(() => {
	vi.unstubAllGlobals()
	window.localStorage.clear()
})

describe('App mount', () => {
	it('keeps the shipped modes when nothing is stored', async () => {
		const { vm } = await mountPage()

		expect([vm.safeModeEnabled, vm.debugModeEnabled, vm.dryRunEnabled]).toEqual([true, false, false])
	})

	it('restores the stored modes from the current keys', async () => {
		window.localStorage.setItem('versioniq_safe_mode', 'false')
		window.localStorage.setItem('versioniq_debug_mode', 'true')
		window.localStorage.setItem('versioniq_dry_run_mode', 'true')
		const { vm } = await mountPage()

		expect([vm.safeModeEnabled, vm.debugModeEnabled, vm.dryRunEnabled]).toEqual([false, true, true])
	})

	it('falls back to the keys from before the rename', async () => {
		window.localStorage.setItem('app_versions_safe_mode', 'false')
		window.localStorage.setItem('app_versions_debug_mode', 'yes')
		window.localStorage.setItem('app_versions_dry_run_mode', 'true')
		const { vm } = await mountPage()

		expect([vm.safeModeEnabled, vm.debugModeEnabled, vm.dryRunEnabled]).toEqual([false, false, true])
	})

	it('reads any stored safe-mode value but "false" as on', async () => {
		window.localStorage.setItem('versioniq_safe_mode', 'off')
		const { vm } = await mountPage()

		expect(vm.safeModeEnabled).toBe(true)
	})

	it('asks for the update channel and the apps first, then the non-blocking lists', async () => {
		const { vm, paths } = await mountPage()

		expect(paths).toEqual(['/api/update-channel', '/api/apps', '/api/advisories', '/api/pins', '/api/policies', '/api/advisory/settings', '/api/updates'])
		expect(vm.isLoading).toBe(false)
		expect(vm.errorMessage).toBe('')
	})
})
