// SPDX-License-Identifier: EUPL-1.2
// Pins how the page moves between its tabs: clicks, the WAI-ARIA arrow keys,
// a settings link in the location hash, and the Discover and Sources panels
// handing the admin over to another tab.
// @spec openspec/specs/app-discovery/spec.md
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

type Call = { method: string, path: string, query: Record<string, string>, headers: Record<string, string>, body: unknown }
type Answer = { meta?: Record<string, unknown>, data?: unknown }
type Route = Answer | ((call: Call) => Answer | Promise<Answer>)

// Child components fetch their own data and are not under test; each is a stub
// whose events the page's handlers are driven through.
const stubs = Object.fromEntries([
	'AdvisoriesPanel', 'AppSourceBadges', 'AutoUpdateOverview', 'CachePanel', 'ChangelogRangePanel', 'DiscoverPanel',
	'HistoryPanel', 'InstallResultNotices', 'InstanceSettingsPanel', 'IntegrationsPanel', 'PinDriftBanner', 'PolicySelector',
	'ReleaseDate', 'ServerCompatBadge', 'SourceOverrideField', 'SourcesPanel', 'TokensPanel', 'TrustedSourcesPanel',
	'VersionChangelog', 'DowngradeConfirmDialog', 'EnableAppDialog', 'PinDialog', 'PinOverrideDialog', 'ShaMismatchDialog',
].map((name) => [name, true]))

/**
 * Mounts the page with a fetch that records every call and answers the
 * longest route ("METHOD /path" before "/path") matching the path.
 *
 * @param routes path prefix (after the app's OCS base) => answer
 * @param options.attach mount into the document, for focus checks
 */
async function mountPage (routes: Record<string, Route> = {}, options: { attach?: boolean } = {}) {
	const calls: Call[] = []
	vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> => {
		const url = new URL(String(input), 'http://localhost')
		const path = url.pathname.replace('/ocs/v2.php/apps/versioniq', '')
		const query = Object.fromEntries([...url.searchParams].filter(([key]) => key !== 'format'))
		const method = init.method ?? 'GET'
		const call = { method, path, query, headers: { ...(init.headers as Record<string, string>) }, body: init.body === undefined ? null : JSON.parse(String(init.body)) }
		calls.push(call)
		const keys = Object.keys(routes).sort((a, b) => b.length - a.length)
		const key = keys.find((k) => k.startsWith(`${method} `) && path.startsWith(k.slice(method.length + 1)))
			?? keys.find((k) => !k.includes(' ') && path.startsWith(k))
		const route = key === undefined ? { data: path === '/api/apps' ? { apps: [] } : {} } : routes[key]
		const answer = typeof route === 'function' ? await route(call) : route
		const body = { ocs: { meta: answer.meta ?? { status: 'ok', statuscode: 200 }, data: answer.data ?? {} } }

		return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
	}))
	const wrapper = mount(App, { global: { stubs }, attachTo: options.attach ? document.body : undefined })
	await flushPromises()

	return { wrapper, vm: wrapper.vm as unknown as Record<string, any>, calls }
}


const TAB_IDS = ['apps', 'advisories', 'history', 'sources', 'tokens', 'trusted', 'discover', 'cache', 'settings']

enableAutoUnmount(afterEach)

beforeEach(() => {
	// jsdom does not implement scrolling; the page scrolls a hash anchor into view.
	Element.prototype.scrollIntoView = vi.fn()
})

afterEach(() => {
	vi.unstubAllGlobals()
	window.history.replaceState(null, '', '#')
	document.body.innerHTML = ''
})

describe('App tabs', () => {
	it('labels one tab per section and selects the clicked one', async () => {
		const { wrapper, vm } = await mountPage()
		const tabs = wrapper.findAll('[role="tablist"] [role="tab"]')

		expect(tabs.map((tab) => tab.text())).toEqual(['Apps', 'Advisories', 'History', 'Sources', 'Tokens', 'Trusted sources', 'Discover', 'Artifact cache', 'Settings'])
		await tabs[3].trigger('click')

		expect(vm.currentTab).toBe('sources')
		expect(wrapper.find('#sources-tab').attributes('aria-selected')).toBe('true')
		expect(wrapper.find('#apps-tab').attributes('aria-selected')).toBe('false')
	})

	it('moves with the arrow keys, wrapping at both ends, and focuses the new tab', async () => {
		const { wrapper, vm } = await mountPage({}, { attach: true })
		const tablist = wrapper.find('[role="tablist"]')

		await tablist.trigger('keydown', { key: 'ArrowLeft' })
		await flushPromises()
		expect(vm.currentTab).toBe('settings')
		expect(document.activeElement?.id).toBe('settings-tab')

		await tablist.trigger('keydown', { key: 'ArrowRight' })
		await flushPromises()
		expect(vm.currentTab).toBe('apps')
		expect(document.activeElement?.id).toBe('apps-tab')

		await tablist.trigger('keydown', { key: 'ArrowRight' })
		await flushPromises()
		expect(vm.currentTab).toBe('advisories')
	})

	it('jumps to the last and first tab with End and Home', async () => {
		const { wrapper, vm } = await mountPage()
		const tablist = wrapper.find('[role="tablist"]')

		await tablist.trigger('keydown', { key: 'End' })
		await flushPromises()
		expect(vm.currentTab).toBe(TAB_IDS[TAB_IDS.length - 1])

		await tablist.trigger('keydown', { key: 'Home' })
		await flushPromises()
		expect(vm.currentTab).toBe('apps')
	})

	it('ignores other keys without cancelling them', async () => {
		const { wrapper, vm } = await mountPage()
		const event = new KeyboardEvent('keydown', { key: 'Enter', cancelable: true })

		wrapper.find('[role="tablist"]').element.dispatchEvent(event)
		await flushPromises()

		expect(vm.currentTab).toBe('apps')
		expect(event.defaultPrevented).toBe(false)
	})

	it('cancels the arrow key it handles', async () => {
		const { wrapper } = await mountPage()
		const event = new KeyboardEvent('keydown', { key: 'ArrowRight', cancelable: true })

		wrapper.find('[role="tablist"]').element.dispatchEvent(event)
		await flushPromises()

		expect(event.defaultPrevented).toBe(true)
	})
})

describe('App location hash', () => {
	it('opens the tab of a settings link on mount and scrolls to its anchor', async () => {
		window.history.replaceState(null, '', '#section-advisories')
		const { vm } = await mountPage({}, { attach: true })
		await flushPromises()

		expect(vm.currentTab).toBe('apps')
		expect(Element.prototype.scrollIntoView).toHaveBeenCalledTimes(1)
		expect((vi.mocked(Element.prototype.scrollIntoView).mock.instances[0] as unknown as HTMLElement).id).toBe('section-advisories')
	})

	it('follows a later hash change to the Sources tab', async () => {
		const { vm } = await mountPage()

		window.history.replaceState(null, '', '#section-sources')
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		await flushPromises()

		expect(vm.currentTab).toBe('sources')
	})

	it('stays put for an unknown anchor and for the Integrations anchor without integriq', async () => {
		const { vm } = await mountPage()

		window.history.replaceState(null, '', '#nowhere')
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		window.history.replaceState(null, '', '#section-integrations')
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		await flushPromises()

		expect(vm.currentTab).toBe('apps')
	})

	it('stops listening for hash changes once unmounted', async () => {
		const { wrapper, vm } = await mountPage()
		wrapper.unmount()

		window.history.replaceState(null, '', '#section-sources')
		window.dispatchEvent(new HashChangeEvent('hashchange'))
		await flushPromises()

		expect(vm.currentTab).toBe('apps')
	})
})

describe('App panel hand-overs', () => {
	it('opens an installed Discover hit in the Apps tab with its versions loaded', async () => {
		const { wrapper, vm, calls } = await mountPage({ '/api/app/deck/versions': { data: { versions: [{ version: '1.2.0' }], installedVersion: '1.0.0', sourceId: 'appstore' } } })
		vm.currentTab = 'discover'
		calls.length = 0

		wrapper.findComponent({ name: 'DiscoverPanel' }).vm.$emit('openApp', 'deck')
		await flushPromises()

		expect([vm.currentTab, vm.appFilter, vm.selectedApp]).toEqual(['apps', 'deck', 'deck'])
		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['GET /api/app/deck/versions'])
		expect([vm.installedVersion, vm.availableSource]).toEqual(['1.0.0', 'appstore'])
	})

	it('hands an installable Discover candidate to the Sources bind form', async () => {
		const { wrapper, vm } = await mountPage()
		const payload = { appId: 'deck', sourceType: 'github', identifier: 'nextcloud/deck' }

		wrapper.findComponent({ name: 'DiscoverPanel' }).vm.$emit('prefillBind', payload)
		await flushPromises()

		expect(vm.currentTab).toBe('sources')
		expect(wrapper.findComponent({ name: 'SourcesPanel' }).props('prefill')).toEqual(payload)
	})

	it('sends a non-installable Discover hit to the Trusted sources tab', async () => {
		const { wrapper, vm } = await mountPage()

		wrapper.findComponent({ name: 'DiscoverPanel' }).vm.$emit('openTrusted')
		await flushPromises()

		expect(vm.currentTab).toBe('trusted')
	})

	it('reloads the versions when the selected app is bound, keeping the install result', async () => {
		const { wrapper, vm, calls } = await mountPage({ '/api/app/deck/versions': { data: { versions: [{ version: '2.0.0' }], installedVersion: '1.0.0', sourceId: 'github' } } })
		vm.selectedApp = 'deck'
		vm.lastInstallResult = { appId: 'deck', toVersion: '1.0.0', message: 'Done.' }
		vm.hasInstallResult = true
		calls.length = 0

		wrapper.findComponent({ name: 'SourcesPanel' }).vm.$emit('bound', 'deck')
		await flushPromises()

		expect(calls.map((call) => call.path)).toEqual(['/api/app/deck/versions'])
		expect(vm.availableSource).toBe('github')
		expect(vm.hasInstallResult).toBe(true)
	})

	it('leaves the versions alone when another app is bound', async () => {
		const { wrapper, vm, calls } = await mountPage()
		vm.selectedApp = 'deck'
		calls.length = 0

		wrapper.findComponent({ name: 'SourcesPanel' }).vm.$emit('bound', 'mail')
		await flushPromises()

		expect(calls).toEqual([])
	})
})
