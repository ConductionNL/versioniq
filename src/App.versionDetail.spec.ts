// SPDX-License-Identifier: EUPL-1.2
// Pins the detail view of a chosen app: its version list and filter, the
// safe-mode refusal, the checksum badge, the pin and unpin buttons, the
// drift banner's re-pin, the one-off source override, the detail tabs, the
// install result panel with its debug output, and the dialogs' open state.
// @spec openspec/specs/version-management/spec.md
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
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

const refused = (message: string): Answer => ({ meta: { status: 'failure', statuscode: 403, message } })

const APPS = [{ id: 'deck', label: 'Deck', summary: '', description: '', preview: '', isCore: false, installedVersion: '1.2.0', state: 'disabled' }]
const VERSIONS = [
	{ version: '1.4.0', recordedSha: 'abc123' },
	{ version: '1.3.0-beta.1' },
	{ version: '1.2.0', recordedSha: 'def456' },
	{ version: '1.0.0', cachedOffline: true },
]
const DETAIL: Record<string, Route> = {
	'/api/update-channel': { data: { updateChannel: 'stable' } },
	'/api/apps': { data: { apps: APPS } },
	'/api/app/deck/versions': { data: { versions: VERSIONS, installedVersion: '1.2.0', sourceId: 'appstore' } },
}

type Page = Awaited<ReturnType<typeof mountPage>>

/**
 * Mounts the page and chooses Deck, so its versions are listed.
 *
 * @param routes extra routes on top of the Deck detail ones
 */
async function openDeck (routes: Record<string, Route> = {}): Promise<Page> {
	const page = await mountPage({ ...DETAIL, ...routes })
	await page.vm.onPickApp('deck')
	await flushPromises()
	page.calls.length = 0

	return page
}

const listed = (wrapper: Page['wrapper']) => wrapper.findAll('li').map((item) => item.find('span').text())
const button = (wrapper: Page['wrapper'], text: string) => wrapper.findAll('button').find((candidate) => candidate.text() === text)

enableAutoUnmount(afterEach)

afterEach(() => {
	vi.unstubAllGlobals()
	window.localStorage.clear()
	delete (window as Window & { OC?: unknown }).OC
})

describe('App version list', () => {
	it('hides the downgrades, and the pre-releases on a stable channel, that safe mode blocks, lists them all without it', async () => {
		const { wrapper, vm } = await openDeck()

		expect(listed(wrapper)).toEqual(['1.4.0', '1.2.0'])
		await wrapper.findAll('input[type="checkbox"]')[0].setValue(false)

		expect(vm.safeModeEnabled).toBe(false)
		expect(listed(wrapper)).toEqual(['1.4.0', '1.3.0-beta.1', '1.2.0', '1.0.0'])
		expect(wrapper.find('[data-testid="cached-offline-badge"]').text()).toBe('Available offline')
	})

	it('filters the versions by the typed text', async () => {
		const { wrapper } = await openDeck()

		await wrapper.find('input[aria-label="Filter versions"]').setValue(' 1.4 ')
		expect(listed(wrapper)).toEqual(['1.4.0'])

		await wrapper.find('input[aria-label="Filter versions"]').setValue('9.9')
		expect(listed(wrapper)).toEqual([])
		expect(wrapper.text()).toContain('No versions match your filter.')
	})

	it('marks a recorded checksum, and a fresh match once the install verified it', async () => {
		const { wrapper, vm } = await openDeck()

		expect(wrapper.find('[title="abc123"]').text()).toBe('Checksum recorded')
		vm.lastInstallResult = { appId: 'deck', toVersion: '1.4.0', recordedShaMatched: true, message: '' }
		await flushPromises()

		expect(wrapper.find('[title="abc123"]').text()).toBe('Matches first-install checksum')
		expect(wrapper.find('[title="def456"]').text()).toBe('Checksum recorded')
	})

	it('selects a version, shows only it with the update action, and "Pick other" goes back', async () => {
		const { wrapper, vm } = await openDeck()

		await button(wrapper, 'Select')!.trigger('click')
		expect(vm.selectedVersion).toBe('1.4.0')
		expect(listed(wrapper)).toEqual(['1.4.0'])
		expect(wrapper.text()).toContain('Selected')
		expect(button(wrapper, 'Update')).toBeDefined()
		expect(wrapper.find('input[aria-label="Filter versions"]').exists()).toBe(false)

		await button(wrapper, 'Pick other')!.trigger('click')
		expect(vm.selectedVersion).toBe('')
		expect(listed(wrapper)).toEqual(['1.4.0', '1.2.0'])
	})

	it('refuses to select a downgrade in safe mode and says why', async () => {
		const { vm } = await openDeck()

		vm.onSelectVersion('1.0.0')

		expect(vm.selectedVersion).toBe('')
		expect(vm.errorMessage).toBe('Safe mode is enabled. Disable it to downgrade or to install a pre-release.')
	})

	it('shows the downgrade warning for an older version once safe mode is off', async () => {
		const { wrapper, vm } = await openDeck()
		vm.safeModeEnabled = false
		await flushPromises()

		vm.onSelectVersion('1.0.0')
		await flushPromises()

		expect(vm.errorMessage).toBe('')
		expect(listed(wrapper)).toEqual(['1.0.0'])
		expect(wrapper.text()).toContain('Downgrade path detected.')
		expect(button(wrapper, 'Degrade')).toBeDefined()
	})

	it('shows nothing when the selected version is no longer in the list', async () => {
		const { wrapper, vm } = await openDeck()
		vm.safeModeEnabled = false
		vm.selectedVersion = '9.9.9'
		await flushPromises()

		expect(listed(wrapper)).toEqual([])
	})

	it('reloads from a one-off source, sending it with the request', async () => {
		const { wrapper, vm, calls } = await openDeck()

		wrapper.findComponent({ name: 'SourceOverrideField' }).vm.$emit('apply', 'github:acme/deck')
		await flushPromises()

		expect(vm.sourceOverride).toBe('github:acme/deck')
		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['GET /api/app/deck/versions'])
		expect(Object.values(calls[0].query)).toContain('github:acme/deck')
	})

	it('shows the error and no source when listing the versions fails', async () => {
		const { wrapper, vm } = await openDeck({ '/api/app/deck/versions': refused('Source unreachable') })
		vm.selectedApp = 'deck'

		await vm.checkVersions()
		await flushPromises()

		expect([vm.errorMessage, vm.availableSource, vm.isCheckingVersions]).toEqual(['Source unreachable', '', false])
		expect(wrapper.text()).toContain('Source unreachable')
	})

	it('"Choose another app" clears the selection and the error', async () => {
		const { wrapper, vm } = await openDeck()
		vm.errorMessage = 'Old error'
		vm.sourceOverride = 'github:acme/deck'
		await flushPromises()

		await button(wrapper, 'Choose another app')!.trigger('click')

		expect([vm.selectedApp, vm.errorMessage, vm.installedVersion, vm.sourceOverride]).toEqual(['', '', '', ''])
		expect(vm.versions).toEqual([])
		expect(wrapper.find('[data-app-id="deck"]').exists()).toBe(true)
	})
})

describe('App detail tabs', () => {
	it('switches between the versions and the app\'s history', async () => {
		const { wrapper, vm } = await openDeck()
		const tabs = wrapper.findAll('[role="tablist"]')[1].findAll('button')

		await tabs[1].trigger('click')
		expect(vm.appDetailTab).toBe('history')
		expect(tabs[1].attributes('aria-selected')).toBe('true')
		expect(wrapper.findComponent({ name: 'HistoryPanel' }).props('appId')).toBe('deck')
		expect(wrapper.findAll('li')).toEqual([])

		await tabs[0].trigger('click')
		expect(vm.appDetailTab).toBe('versions')
		expect(listed(wrapper)).toEqual(['1.4.0', '1.2.0'])
	})
})

describe('App detail pin', () => {
	it('opens the pin dialog for the installed version and follows its open state', async () => {
		const { wrapper, vm } = await openDeck()

		await button(wrapper, 'Pin this version')!.trigger('click')
		const dialog = wrapper.findComponent({ name: 'PinDialog' })
		expect(dialog.props()).toMatchObject({ open: true, appId: 'deck', version: '1.2.0' })

		dialog.vm.$emit('update:open', false)
		await flushPromises()
		expect(vm.isPinDialogOpen).toBe(false)
	})

	it('unpins a pinned app from the detail view', async () => {
		const { wrapper, vm, calls } = await openDeck()
		vm.pins = { deck: { appId: 'deck', version: '1.2.0', pinnedBy: 'admin', pinnedAt: '2026-01-01' } }
		await flushPromises()
		expect(wrapper.find('[data-testid="pin-badge-detail"]').text()).toBe('📌 Pinned 1.2.0')

		await button(wrapper, 'Unpin')!.trigger('click')
		await flushPromises()

		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['DELETE /api/app/deck/pin'])
		expect(vm.pins).toEqual({})
		expect(button(wrapper, 'Pin this version')).toBeDefined()
	})

	it('re-pins a drifted app by installing the pinned version, then reloads pins and versions', async () => {
		const { wrapper, vm, calls } = await openDeck({ '/api/pins': { data: { pins: [] } } })
		vm.pins = { deck: { appId: 'deck', version: '1.4.0', pinnedBy: 'admin', pinnedAt: '', driftedTo: '1.2.0' } }
		await flushPromises()

		wrapper.findComponent({ name: 'PinDriftBanner' }).vm.$emit('repinRequested', 'deck', '1.4.0')
		await flushPromises()

		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['POST /api/app/deck/versions/1.4.0/install', 'GET /api/pins', 'GET /api/app/deck/versions'])
		expect(calls[0].query).toEqual({ debug: '0', dryRun: '0', targetVersion: '1.4.0' })
		expect([vm.isInstallingVersion, vm.errorMessage]).toEqual([false, ''])
	})

	it('shows the installer\'s refusal of a re-pin, and does not reload another app\'s versions', async () => {
		const { vm, calls } = await openDeck({ 'POST /api/app/mail/versions': refused('Pinned version is gone') })
		vm.pins = { mail: { appId: 'mail', version: '2.0.0', pinnedBy: 'admin', pinnedAt: '' } }

		await vm.onRepinRequested('mail', '2.0.0')
		await flushPromises()

		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['POST /api/app/mail/versions/2.0.0/install', 'GET /api/pins'])
		expect(vm.errorMessage).toBe('Pinned version is gone')
	})

	it('reports a re-pin that threw, and ignores one while an install runs', async () => {
		const { vm, calls } = await openDeck({ 'POST /api/app/deck/versions': () => { throw new Error('Network down') } })

		await vm.onRepinRequested('deck', '1.2.0')
		await flushPromises()
		expect(vm.errorMessage).toBe('Network down')

		vm.isInstallingVersion = true
		calls.length = 0
		await vm.onRepinRequested('deck', '1.2.0')
		expect(calls).toEqual([])
	})

	it('settles a re-pin that is a downgrade only after the dialog answers', async () => {
		const { wrapper, vm, calls } = await openDeck()
		vm.pins = { deck: { appId: 'deck', version: '1.0.0', pinnedBy: 'admin', pinnedAt: '', driftedTo: '1.2.0' } }
		await flushPromises()

		const repin = vm.onRepinRequested('deck', '1.0.0')
		await flushPromises()
		const dialog = wrapper.findComponent({ name: 'DowngradeConfirmDialog' })
		expect(dialog.props()).toMatchObject({ open: true, fromVersion: '1.2.0', toVersion: '1.0.0' })
		dialog.vm.$emit('update:open', false)
		await flushPromises()
		expect(vm.isDowngradeConfirmOpen).toBe(false)

		dialog.vm.$emit('resolve', true)
		await repin
		await flushPromises()
		const installs = calls.filter((call) => call.method === 'POST')
		expect(installs.map((call) => call.query)).toEqual([
			{ debug: '0', dryRun: '1', targetVersion: '1.0.0' },
			{ debug: '0', dryRun: '0', targetVersion: '1.0.0', allowDowngrade: '1' },
		])
	})

	it('cancels a pending downgrade question when a new one is asked', async () => {
		const { vm } = await openDeck()

		const first = vm.confirmDowngrade('deck', '1.2.0', '1.0.0')
		await flushPromises()
		const second = vm.confirmDowngrade('deck', '1.2.0', '0.9.0')
		await flushPromises()

		await expect(first).resolves.toBe(false)
		expect(vm.downgradeConfirmToVersion).toBe('0.9.0')
		vm.onDowngradeResolved(true)
		await expect(second).resolves.toBe(true)
	})

	it('falls back to the generic warning when the downgrade preview fails', async () => {
		const { vm } = await openDeck({ 'POST /api/app/deck/versions': () => { throw new Error('offline') } })

		void vm.confirmDowngrade('deck', '1.2.0', '1.0.0')
		await flushPromises()

		expect(vm.downgradeOrphanedMigrations).toBeNull()
		expect(vm.isDowngradeConfirmOpen).toBe(true)
	})
})

describe('App install dialogs', () => {
	it('closes the pin-override and checksum dialogs when they ask to', async () => {
		const { wrapper, vm } = await openDeck()
		vm.isPinOverrideDialogOpen = true
		vm.isShaMismatchDialogOpen = true
		await flushPromises()

		wrapper.findComponent({ name: 'PinOverrideDialog' }).vm.$emit('update:open', false)
		wrapper.findComponent({ name: 'ShaMismatchDialog' }).vm.$emit('update:open', false)
		await flushPromises()

		expect([vm.isPinOverrideDialogOpen, vm.isShaMismatchDialogOpen]).toEqual([false, false])
	})

	it('cancels an unanswered pin-override and checksum question when a new one is asked', async () => {
		const { vm } = await openDeck()

		const pinFirst = vm.confirmPinOverride('deck', '1.0.0', '1.4.0')
		const pinSecond = vm.confirmPinOverride('deck', '1.0.0', '1.4.0')
		const shaFirst = vm.confirmShaMismatch('deck', '1.4.0', 'aa', 'bb')
		const shaSecond = vm.confirmShaMismatch('deck', '1.4.0', 'aa', 'cc')

		await expect(pinFirst).resolves.toBe('cancel')
		await expect(shaFirst).resolves.toBe(false)
		vm.onPinOverrideResolve('repin')
		vm.onShaMismatchResolve(true)
		await expect(pinSecond).resolves.toBe('repin')
		await expect(shaSecond).resolves.toBe(true)
		expect(vm.shaMismatchActualSha).toBe('cc')
	})
})

describe('App install result panel', () => {
	it('offers to enable an app installed live but left disabled', async () => {
		const { wrapper, vm } = await openDeck()
		vm.hasInstallResult = true
		vm.lastInstallResult = { appId: 'deck', fromVersion: '1.2.0', toVersion: '1.4.0', message: 'Installed.', dryRun: false, installStatus: 'installed' }
		await flushPromises()

		const enable = wrapper.find('[data-testid="install-enable-hint"]')
		expect(enable.text()).toContain('This app is installed but not enabled.')
		expect(enable.text()).toContain('Enable deck')
		await wrapper.find('[data-testid="install-enable-button"]').trigger('click')

		expect(wrapper.findComponent({ name: 'EnableAppDialog' }).props()).toMatchObject({ open: true, appId: 'deck' })
	})

	it('does not offer to enable after a dry run', async () => {
		const { wrapper, vm } = await openDeck()
		vm.hasInstallResult = true
		vm.lastInstallResult = { appId: 'deck', toVersion: '1.4.0', message: 'Evaluated.', dryRun: true, installStatus: 'dry-run' }
		await flushPromises()

		expect(wrapper.find('[data-testid="install-enable-hint"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Dry-run (no write)')
	})

	it('renders the debug steps as indented lines, with "No details" for an empty step', async () => {
		const { wrapper, vm } = await openDeck()
		vm.debugModeEnabled = true
		vm.hasInstallResult = true
		vm.lastInstallResult = { appId: 'deck', toVersion: '1.4.0', message: 'Done.', dryRun: false, installStatus: 'installed', category: 'download', stage: 'verify' }
		vm.lastInstallDebug = [
			{ stage: 'download', data: { url: 'https://example.test/deck.tar.gz', size: 12, ok: true, sha: null, big: 10n, parts: [], meta: {}, list: ['a', { b: 1 }] } },
			{ stage: 'verify', data: '   ' },
			{ stage: 'extract', data: null },
			{ stage: 'enable', data: 'done' },
			{ stage: 'empty', data: [] },
		]
		await flushPromises()

		const steps = wrapper.findAll('article').filter((step) => step.find('details').exists() || step.text().includes('No details'))
		expect(steps).toHaveLength(5)
		expect(steps[0].findAll('li').map((line) => line.element.textContent!.replace(/^\s+|\s+$/g, ''))).toEqual([
			'url: https://example.test/deck.tar.gz',
			'size: 12',
			'ok: true',
			'sha: —',
			'big: 10',
			'parts:',
			'[]',
			'meta:',
			'{}',
			'list:',
			'[0]: a',
			'[1]:',
			'b: 1',
		])
		expect(steps[0].find('details').attributes('open')).toBeDefined()
		expect(steps[1].text()).toContain('No details')
		expect(steps[2].text()).toContain('No details')
		expect(steps[3].findAll('li').map((line) => line.text())).toEqual(['done'])
		expect(steps[4].findAll('li').map((line) => line.text())).toEqual(['[]'])
		expect(wrapper.text()).toContain('Install debug (5 step(s))')
		expect(wrapper.text()).toContain('Failure category')
		expect(wrapper.text()).toContain('Failed at stage')
	})
})
