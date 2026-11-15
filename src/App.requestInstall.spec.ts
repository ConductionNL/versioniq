// SPDX-License-Identifier: EUPL-1.2
// Pins the install request every install path sends: the query flags, URL and body.
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
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: { name: 'NcDialog', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcPasswordField', () => ({ default: { name: 'NcPasswordField', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<div><slot /></div>' } }))

type Recorded = { url: string, method?: string, body?: unknown }

/**
 * Stubs fetch: every call answers an empty OCS success, except install calls,
 * which take the next queued answer.
 *
 * @param installAnswers OCS envelopes returned to successive install calls.
 */
function stubFetch (installAnswers: unknown[]): Recorded[] {
	const installs: Recorded[] = []
	vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
		const url = String(input)
		let body: unknown = { ocs: { meta: { status: 'ok', statuscode: 200 }, data: {} } }
		if (url.includes('/install')) {
			installs.push({ url, method: init?.method, body: init?.body })
			body = installAnswers.shift() ?? body
		}
		return Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }))
	}))
	return installs
}

/**
 * Mounts the page with the other tabs' panels stubbed.
 */
async function mountApp () {
	const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
	await flushPromises()
	return wrapper.vm as unknown as Record<string, any>
}

const failure = (data: Record<string, unknown>) => ({ ocs: { meta: { status: 'failure', statuscode: 409, message: 'refused' }, data } })

afterEach(() => {
	vi.unstubAllGlobals()
	window.localStorage.clear()
})

describe('install request', () => {
	it('sends a forced dry run for the downgrade preview, with debug and source override', async () => {
		const installs = stubFetch([{ ocs: { meta: { status: 'ok', statuscode: 200 }, data: { orphanedMigrations: ['m1'] } } }])
		const vm = await mountApp()
		vm.debugModeEnabled = true
		vm.sourceOverride = 'https://example.test/app.tar.gz'
		expect(await vm.fetchDowngradePreview('calendar', '1.0.0')).toEqual(['m1'])
		expect(installs).toMatchInlineSnapshot(`
			[
			  {
			    "body": "{"version":"1.0.0"}",
			    "method": "POST",
			    "url": "http://localhost:3000/ocs/v2.php/apps/versioniq/api/app/calendar/versions/1.0.0/install?debug=1&dryRun=1&targetVersion=1.0.0&source=https%3A%2F%2Fexample.test%2Fapp.tar.gz&format=json",
			  },
			]
		`)
	})

	it('previews the downgrade, then sends allowDowngrade, overridePin, then acceptNewSha through the install retries', async () => {
		const installs = stubFetch([
			{ ocs: { meta: { status: 'ok', statuscode: 200 }, data: {} } },
			failure({ category: 'pinned', pinnedVersion: '4.0.0' }),
			failure({ code: 'sha_mismatch', expectedSha: 'aa', actualSha: 'bb' }),
		])
		const vm = await mountApp()
		vm.dryRunEnabled = true
		vm.selectedApp = 'calendar'
		vm.installedVersion = '5.2.1'
		vm.selectedVersion = '5.0.0'
		const run = vm.performInstall()
		await flushPromises()
		vm.onDowngradeResolved(true)
		await flushPromises()
		vm.onPinOverrideResolve('unpin')
		await flushPromises()
		vm.onShaMismatchResolve(true)
		await run
		expect(installs).toMatchInlineSnapshot(`
			[
			  {
			    "body": "{"version":"5.0.0"}",
			    "method": "POST",
			    "url": "http://localhost:3000/ocs/v2.php/apps/versioniq/api/app/calendar/versions/5.0.0/install?debug=0&dryRun=1&targetVersion=5.0.0&format=json",
			  },
			  {
			    "body": "{"version":"5.0.0"}",
			    "method": "POST",
			    "url": "http://localhost:3000/ocs/v2.php/apps/versioniq/api/app/calendar/versions/5.0.0/install?debug=0&dryRun=1&targetVersion=5.0.0&allowDowngrade=1&format=json",
			  },
			  {
			    "body": "{"version":"5.0.0"}",
			    "method": "POST",
			    "url": "http://localhost:3000/ocs/v2.php/apps/versioniq/api/app/calendar/versions/5.0.0/install?debug=0&dryRun=1&targetVersion=5.0.0&overridePin=unpin&allowDowngrade=1&format=json",
			  },
			  {
			    "body": "{"version":"5.0.0"}",
			    "method": "POST",
			    "url": "http://localhost:3000/ocs/v2.php/apps/versioniq/api/app/calendar/versions/5.0.0/install?debug=0&dryRun=1&targetVersion=5.0.0&acceptNewSha=1&allowDowngrade=1&format=json",
			  },
			]
		`)
	})

	it('sends a plain install for an upgrade and for a re-pin without drift', async () => {
		const installs = stubFetch([])
		const vm = await mountApp()
		vm.selectedApp = 'deck'
		vm.installedVersion = '1.0.0'
		vm.selectedVersion = '1.2.0'
		await vm.performInstall()
		await vm.onRepinRequested('deck', '1.2.0')
		expect(installs).toMatchInlineSnapshot(`
			[
			  {
			    "body": "{"version":"1.2.0"}",
			    "method": "POST",
			    "url": "http://localhost:3000/ocs/v2.php/apps/versioniq/api/app/deck/versions/1.2.0/install?debug=0&dryRun=0&targetVersion=1.2.0&format=json",
			  },
			  {
			    "body": "{"version":"1.2.0"}",
			    "method": "POST",
			    "url": "http://localhost:3000/ocs/v2.php/apps/versioniq/api/app/deck/versions/1.2.0/install?debug=0&dryRun=0&targetVersion=1.2.0&format=json",
			  },
			]
		`)
	})

	it('sends allowDowngrade for a confirmed re-pin to a lower version than the drift', async () => {
		const installs = stubFetch([])
		const vm = await mountApp()
		vm.pins = { deck: { appId: 'deck', version: '1.2.0', driftedTo: '1.3.0' } }
		const run = vm.onRepinRequested('deck', '1.2.0')
		await flushPromises()
		vm.onDowngradeResolved(true)
		await run
		expect(installs).toMatchInlineSnapshot(`
			[
			  {
			    "body": "{"version":"1.2.0"}",
			    "method": "POST",
			    "url": "http://localhost:3000/ocs/v2.php/apps/versioniq/api/app/deck/versions/1.2.0/install?debug=0&dryRun=1&targetVersion=1.2.0&format=json",
			  },
			  {
			    "body": "{"version":"1.2.0"}",
			    "method": "POST",
			    "url": "http://localhost:3000/ocs/v2.php/apps/versioniq/api/app/deck/versions/1.2.0/install?debug=0&dryRun=0&targetVersion=1.2.0&allowDowngrade=1&format=json",
			  },
			]
		`)
	})
})
