// SPDX-License-Identifier: EUPL-1.2
// Pins how the page keeps its pin list in step with the pin dialog, the
// drift banner and the unpin call, and how it saves the automatic-update
// and advisory settings.
// @spec openspec/specs/version-pinning/spec.md
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
// The page's own logic is under test; the library components are not.
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcDialog', () => ({ default: { name: 'NcDialog', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcPasswordField', () => ({ default: { name: 'NcPasswordField', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<div><slot /></div>' } }))

type Call = { method: string, path: string, body: unknown }
type Answer = { meta?: Record<string, unknown>, data?: unknown, raw?: unknown }

const stubs = { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true }

type Vm = Record<string, unknown> & {
	openPinDialog: (appId: string, version: string) => void
	onPinned: (pin: Record<string, unknown>) => void
	onPinDriftUpdated: (appId: string, pin: Record<string, unknown> | null) => void
	unpinApp: (appId: string) => Promise<void>
	saveAutoUpdateSettings: () => Promise<void>
	saveAdvisorySettings: () => Promise<void>
}

/**
 * Mounts the page with a fetch that records every call made after mounting
 * and answers the first route whose prefix matches the path.
 *
 * @param routes path prefix (after the app's OCS base) => answer
 */
async function mountPage (routes: Record<string, Answer> = {}) {
	const calls: Call[] = []
	let recording = false
	vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> => {
		const url = new URL(String(input), 'http://localhost')
		const path = url.pathname.replace('/ocs/v2.php/apps/versioniq', '') + url.search.replace(/[?&]format=json/, '')
		if (recording) {
			calls.push({ method: init.method ?? 'GET', path, body: init.body === undefined ? null : JSON.parse(String(init.body)) })
		}
		const route = recording ? Object.keys(routes).find((prefix) => path.startsWith(prefix)) : undefined
		const answer: Answer = route ? routes[route] : { data: path.includes('/api/apps') ? { apps: [] } : {} }
		const body = 'raw' in answer ? answer.raw : { ocs: { meta: answer.meta ?? { status: 'ok', statuscode: 200 }, data: answer.data ?? {} } }

		return Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }))
	}))
	const wrapper = mount(App, { global: { stubs } })
	await flushPromises()
	recording = true

	return { vm: wrapper.vm as unknown as Vm, calls }
}

const refused = (message: string): Answer => ({ meta: { status: 'failure', statuscode: 403, message } })

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('App pin list', () => {
	it('opens the pin dialog for an app and version', async () => {
		const { vm } = await mountPage()

		vm.openPinDialog('user_ldap', '2.4.1')

		expect([vm.pinDialogAppId, vm.pinDialogVersion, vm.isPinDialogOpen]).toEqual(['user_ldap', '2.4.1', true])
	})

	it('adds or replaces the pin the dialog stored, keeping the others', async () => {
		const { vm } = await mountPage()
		vm.pins = { mail: { appId: 'mail', version: '3.0.0' }, deck: { appId: 'deck', version: '1.0.0' } }

		vm.onPinned({ appId: 'mail', version: '3.1.0' })
		vm.onPinned({ appId: 'notes', version: '4.0.0' })

		expect(vm.pins).toEqual({
			mail: { appId: 'mail', version: '3.1.0' },
			deck: { appId: 'deck', version: '1.0.0' },
			notes: { appId: 'notes', version: '4.0.0' },
		})
	})

	it('follows the drift banner: a moved pin replaces, a dropped pin is removed', async () => {
		const { vm } = await mountPage()
		vm.pins = { mail: { appId: 'mail', version: '3.0.0' }, deck: { appId: 'deck', version: '1.0.0' } }

		vm.onPinDriftUpdated('mail', { appId: 'mail', version: '3.2.0' })
		vm.onPinDriftUpdated('deck', null)

		expect(vm.pins).toEqual({ mail: { appId: 'mail', version: '3.2.0' } })
	})

	it('unpins with an encoded DELETE and drops the pin', async () => {
		const { vm, calls } = await mountPage()
		vm.pins = { 'a b': { appId: 'a b' }, deck: { appId: 'deck' } }

		await vm.unpinApp('a b')
		await flushPromises()

		expect(calls).toEqual([{ method: 'DELETE', path: '/api/app/a%20b/pin', body: null }])
		expect(vm.pins).toEqual({ deck: { appId: 'deck' } })
		expect(vm.errorMessage).toBe('')
	})

	it('keeps the pin and shows the refusal when unpinning fails', async () => {
		const { vm } = await mountPage({ '/api/app/mail/pin': refused('Forbidden') })
		vm.pins = { mail: { appId: 'mail' } }

		await vm.unpinApp('mail')
		await flushPromises()

		expect(vm.pins).toEqual({ mail: { appId: 'mail' } })
		expect(vm.errorMessage).toBe('Forbidden')
	})
})

describe('App automatic update settings', () => {
	it('refuses a malformed window without calling the server', async () => {
		const { vm, calls } = await mountPage()
		vm.autoUpdateWindowInput = '2am-4am'

		await vm.saveAutoUpdateSettings()

		expect(calls).toEqual([])
		expect(vm.autoUpdateSettingsError).toBe('Window must be in HH:MM-HH:MM format.')
		expect(vm.isSavingAutoUpdateSettings).toBe(false)
	})

	it('sends the switch as 1/0 in query and body and stores the answer as saved', async () => {
		const { vm, calls } = await mountPage({ '/api/auto-update/settings': { data: { autoUpdateEnabled: false, autoUpdateWindow: '01:00-03:00' } } })
		vm.autoUpdateEnabled = false
		vm.autoUpdateWindowInput = ' 01:00-03:00 '

		await vm.saveAutoUpdateSettings()
		await flushPromises()

		expect(calls).toEqual([{
			method: 'PUT',
			path: '/api/auto-update/settings?enabled=0&window=01%3A00-03%3A00',
			body: { enabled: '0', window: '01:00-03:00' },
		}])
		expect([vm.autoUpdateEnabled, vm.savedAutoUpdateEnabled]).toEqual([false, false])
		expect([vm.autoUpdateWindowInput, vm.savedAutoUpdateWindow]).toEqual(['01:00-03:00', '01:00-03:00'])
		expect(vm.autoUpdateSettingsNotice).toBe('Automatic update settings saved.')
		expect(vm.autoUpdateSettingsError).toBe('')
	})

	it('keeps the entered window when the answer carries none', async () => {
		const { vm, calls } = await mountPage({ '/api/auto-update/settings': { data: { autoUpdateEnabled: true } } })
		vm.autoUpdateEnabled = true
		vm.autoUpdateWindowInput = '22:00-23:30'

		await vm.saveAutoUpdateSettings()
		await flushPromises()

		expect(calls[0].body).toEqual({ enabled: '1', window: '22:00-23:30' })
		expect([vm.autoUpdateEnabled, vm.autoUpdateWindowInput, vm.savedAutoUpdateWindow]).toEqual([true, '22:00-23:30', '22:00-23:30'])
	})

	it('shows the refusal and keeps the saved state when the server refuses', async () => {
		const { vm } = await mountPage({ '/api/auto-update/settings': refused('Password confirmation is required') })
		vm.autoUpdateEnabled = true
		vm.autoUpdateWindowInput = '01:00-02:00'
		const savedBefore = [vm.savedAutoUpdateEnabled, vm.savedAutoUpdateWindow]

		await vm.saveAutoUpdateSettings()
		await flushPromises()

		expect(vm.autoUpdateSettingsError).toBe('Password confirmation is required')
		expect(vm.autoUpdateSettingsNotice).toBe('')
		expect([vm.savedAutoUpdateEnabled, vm.savedAutoUpdateWindow]).toEqual(savedBefore)
		expect(vm.isSavingAutoUpdateSettings).toBe(false)
	})
})

describe('App advisory settings', () => {
	it('sends the trimmed interval and the digest as 1/0, then stores the answer', async () => {
		const { vm, calls } = await mountPage({ '/api/advisory/settings': { data: { intervalHours: 12, digestEnabled: false } } })
		vm.advisoryIntervalInput = ' 12 '
		vm.advisoryDigestEnabled = false

		await vm.saveAdvisorySettings()
		await flushPromises()

		expect(calls).toEqual([{ method: 'PUT', path: '/api/advisory/settings', body: { intervalHours: '12', digestEnabled: '0' } }])
		expect([vm.advisoryIntervalInput, vm.advisorySavedInterval]).toEqual(['12', '12'])
		expect([vm.advisoryDigestEnabled, vm.advisorySavedDigest]).toEqual([false, false])
		expect(vm.advisorySettingsNotice).toBe('Advisory settings saved.')
		expect(vm.isSavingAdvisorySettings).toBe(false)
	})

	it('sends an enabled digest as 1', async () => {
		const { vm, calls } = await mountPage({ '/api/advisory/settings': { data: { intervalHours: 24, digestEnabled: true } } })
		vm.advisoryIntervalInput = '24'
		vm.advisoryDigestEnabled = true

		await vm.saveAdvisorySettings()
		await flushPromises()

		expect(calls[0].body).toEqual({ intervalHours: '24', digestEnabled: '1' })
		expect(vm.advisorySavedDigest).toBe(true)
	})

	it('shows the refusal and does not mark anything saved', async () => {
		const { vm } = await mountPage({ '/api/advisory/settings': refused('Interval must be between 1 and 168 hours.') })
		vm.advisoryIntervalInput = '999'
		const savedBefore = vm.advisorySavedInterval

		await vm.saveAdvisorySettings()
		await flushPromises()

		expect(vm.advisorySettingsError).toBe('Interval must be between 1 and 168 hours.')
		expect(vm.advisorySettingsNotice).toBe('')
		expect(vm.advisorySavedInterval).toBe(savedBefore)
		expect(vm.advisoryIntervalInput).toBe('999')
	})

	it('reports a response that is not an object', async () => {
		const { vm } = await mountPage({ '/api/advisory/settings': { raw: null } })

		await vm.saveAdvisorySettings()
		await flushPromises()

		expect(vm.advisorySettingsError).toBe('Unexpected response format')
	})
})
