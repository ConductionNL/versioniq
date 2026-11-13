// SPDX-License-Identifier: EUPL-1.2
// Pins the page's own setting controls: the safe, dry-run and debug switches
// and what they store, the automatic-update and advisory inputs, the
// password confirmation before a write, and a failed first load.
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

type PasswordConfirmation = {
	requiresPasswordConfirmation?: () => boolean
	requirePasswordConfirmation?: (callback: () => void, options: unknown, reject: () => void) => void
}

/**
 * Installs Nextcloud's password confirmation global.
 *
 * @param confirmation what OC.PasswordConfirmation offers
 */
function withPasswordConfirmation (confirmation: PasswordConfirmation): void {
	(window as Window & { OC?: unknown }).OC = { PasswordConfirmation: confirmation }
}

const switches = (wrapper: Awaited<ReturnType<typeof mountPage>>['wrapper']) => wrapper.findAll('input[type="checkbox"]')
const saveButton = (wrapper: Awaited<ReturnType<typeof mountPage>>['wrapper'], testid: string) => wrapper.find(`[data-testid="${testid}"]`)

enableAutoUnmount(afterEach)

afterEach(() => {
	vi.unstubAllGlobals()
	window.localStorage.clear()
	delete (window as Window & { OC?: unknown }).OC
})

describe('App mode switches', () => {
	it('stores each switch under its own key when the admin flips it', async () => {
		const { wrapper, vm } = await mountPage()
		const [safe, dryRun, debug] = switches(wrapper)

		await safe.setValue(false)
		await dryRun.setValue(true)
		await debug.setValue(true)
		await flushPromises()

		expect([vm.safeModeEnabled, vm.dryRunEnabled, vm.debugModeEnabled]).toEqual([false, true, true])
		expect(['versioniq_safe_mode', 'versioniq_dry_run_mode', 'versioniq_debug_mode'].map((key) => window.localStorage.getItem(key))).toEqual(['false', 'true', 'true'])

		await dryRun.setValue(false)
		await debug.setValue(false)
		await flushPromises()
		expect([window.localStorage.getItem('versioniq_dry_run_mode'), window.localStorage.getItem('versioniq_debug_mode')]).toEqual(['false', 'false'])
	})

	it('drops a selected downgrade when safe mode is turned back on', async () => {
		const { vm } = await mountPage()
		vm.safeModeEnabled = false
		vm.installedVersion = '2.0.0'
		vm.selectedVersion = '1.0.0'
		await flushPromises()
		expect(vm.selectedVersion).toBe('1.0.0')

		vm.safeModeEnabled = true
		await flushPromises()

		expect(vm.selectedVersion).toBe('')
		expect(window.localStorage.getItem('versioniq_safe_mode')).toBe('true')
	})

	it('keeps a selected upgrade when safe mode is turned back on', async () => {
		const { vm } = await mountPage()
		vm.safeModeEnabled = false
		vm.installedVersion = '1.0.0'
		vm.selectedVersion = '2.0.0'
		await flushPromises()

		vm.safeModeEnabled = true
		await flushPromises()

		expect(vm.selectedVersion).toBe('2.0.0')
	})
})

describe('App automatic update controls', () => {
	it('flags a malformed window as typed and enables Save only for a valid change', async () => {
		const { wrapper, vm } = await mountPage({ '/api/policies': { data: { policies: [], autoUpdateEnabled: false, autoUpdateWindow: '01:00-05:00', autoUpdateTimeZone: 'Europe/Amsterdam' } } })
		const window = wrapper.find('[data-testid="auto-update-window"]')
		expect(wrapper.text()).toContain('Update window (HH:MM-HH:MM, time zone Europe/Amsterdam)')
		expect(saveButton(wrapper, 'auto-update-settings-save').attributes('disabled')).toBe('true')

		await window.setValue('1am')
		expect(vm.autoUpdateWindowInput).toBe('1am')
		expect(wrapper.find('[data-testid="auto-update-window-error"]').text()).toBe('Use the HH:MM-HH:MM format, e.g. 01:00-05:00.')

		await window.setValue('01:00-05:00')
		await wrapper.find('[data-testid="auto-update-kill-switch"]').setValue(true)
		expect(vm.autoUpdateEnabled).toBe(true)
		expect(wrapper.find('[data-testid="auto-update-window-error"]').exists()).toBe(false)
		expect(saveButton(wrapper, 'auto-update-settings-save').attributes('disabled')).toBe('false')
	})

	it('shows the saved notice and the refusal under the controls', async () => {
		const { wrapper, vm } = await mountPage()
		vm.autoUpdateSettingsNotice = 'Automatic update settings saved.'
		vm.autoUpdateSettingsError = 'Password confirmation is required'
		await flushPromises()

		expect(wrapper.find('[data-testid="auto-update-settings"]').text()).toContain('Automatic update settings saved.')
		expect(wrapper.find('[data-testid="auto-update-settings"]').text()).toContain('Password confirmation is required')
	})
})

describe('App advisory controls', () => {
	it('takes the allowed range from the server and flags an interval outside it', async () => {
		const { wrapper, vm } = await mountPage({ '/api/advisory/settings': { data: { intervalHours: 6, minIntervalHours: 2, maxIntervalHours: 12, digestEnabled: true } } })
		const interval = wrapper.find('[data-testid="advisory-interval"]')
		expect(wrapper.text()).toContain('Check every (hours, 2-12)')
		expect(interval.attributes()).toMatchObject({ min: '2', max: '12' })
		expect(saveButton(wrapper, 'advisory-settings-save').attributes('disabled')).toBe('true')

		// Set on the state, not typed: see the report on the number input's v-model.
		vm.advisoryIntervalInput = '13'
		await flushPromises()
		expect(wrapper.find('[data-testid="advisory-interval-error"]').text()).toBe('Enter a whole number of hours between 2 and 12.')

		vm.advisoryIntervalInput = '8'
		await wrapper.find('[data-testid="advisory-digest-enabled"]').setValue(false)
		expect([vm.advisoryIntervalInput, vm.advisoryDigestEnabled]).toEqual(['8', false])
		expect(wrapper.find('[data-testid="advisory-interval-error"]').exists()).toBe(false)
		expect(saveButton(wrapper, 'advisory-settings-save').attributes('disabled')).toBe('false')
	})

	it('shows the saved notice and the refusal', async () => {
		const { wrapper, vm } = await mountPage()
		vm.advisorySettingsNotice = 'Advisory settings saved.'
		vm.advisorySettingsError = 'Interval must be between 1 and 24 hours.'
		await flushPromises()

		expect(wrapper.find('[data-testid="advisory-settings-notice"]').text()).toBe('Advisory settings saved.')
		expect(wrapper.find('[data-testid="advisory-settings-error"]').text()).toBe('Interval must be between 1 and 24 hours.')
	})
})

describe('App password confirmation', () => {
	it('writes without asking when Nextcloud says no confirmation is needed', async () => {
		const ask = vi.fn()
		withPasswordConfirmation({ requiresPasswordConfirmation: () => false, requirePasswordConfirmation: ask })
		const { vm, calls } = await mountPage()
		calls.length = 0

		await vm.onRetryAttempt('deck', '1.2.1')

		expect(ask).not.toHaveBeenCalled()
		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['DELETE /api/app/deck/attempts/1.2.1'])
	})

	it('writes once the admin confirmed the password', async () => {
		withPasswordConfirmation({ requiresPasswordConfirmation: () => true, requirePasswordConfirmation: (confirmed) => confirmed() })
		const { vm, calls } = await mountPage()
		calls.length = 0

		await vm.onRetryAttempt('deck', '1.2.1')

		expect(calls.map((call) => call.method)).toEqual(['DELETE'])
		expect(vm.errorMessage).toBe('')
	})

	it('sends nothing and says so when the admin cancels the confirmation', async () => {
		withPasswordConfirmation({ requirePasswordConfirmation: (_confirmed, _options, cancelled) => cancelled() })
		const { vm, calls } = await mountPage()
		calls.length = 0

		await vm.onRetryAttempt('deck', '1.2.1')

		expect(calls).toEqual([])
		expect(vm.errorMessage).toBe('Password confirmation was cancelled')
	})
})

describe('App first load', () => {
	it('reports a thrown app list load, ends loading, and still asks for the background lists', async () => {
		const { wrapper, vm, calls } = await mountPage({ '/api/apps': () => { throw new TypeError('Failed to fetch') } })

		expect(vm.errorMessage).toBe('Failed to fetch')
		expect(vm.isLoading).toBe(false)
		expect(calls.map((call) => call.path)).toContain('/api/pins')
		expect(wrapper.find('#app-filter').attributes('disabled')).toBe('')
	})

	it('reports a refused update channel only as a missing channel', async () => {
		const { wrapper, vm } = await mountPage({ '/api/update-channel': refused('nope') })

		expect([vm.updateChannel, vm.errorMessage]).toEqual(['', ''])
		expect(wrapper.text()).not.toContain('Update channel:')
	})
})
