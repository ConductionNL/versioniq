// SPDX-License-Identifier: EUPL-1.2
// pinning-ranges-and-skips: an install the policy's level holds back asks once
// and retries with the audited override; a skipped version asks before it is
// installed by hand; "hold manual installs" is saved with the policy.
// @spec openspec/changes/pinning-ranges-and-skips/specs/auto-update-policies/spec.md#requirement-a-policy-can-hold-manual-installs-to-its-level
// @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
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

const ok = (data: Record<string, unknown> = {}) => ({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } })
const failure = (data: Record<string, unknown>) => ({ ocs: { meta: { status: 'failure', statuscode: 409, message: 'refused' }, data } })

/**
 * Stubs fetch: install calls take the next queued answer, policy writes answer
 * what was sent, everything else an empty success.
 *
 * @param installAnswers OCS envelopes for successive install calls.
 */
function stubFetch (installAnswers: unknown[]): Recorded[] {
	const recorded: Recorded[] = []
	vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
		const url = String(input)
		let body: unknown = ok()
		// The install call itself, not the progress poll beside it (install-live-progress).
		if (/\/install(\?|$)/.test(url)) {
			recorded.push({ url, method: init?.method, body: init?.body })
			body = installAnswers.shift() ?? body
		} else if (url.includes('/policy') && init?.method === 'PUT') {
			recorded.push({ url, method: init?.method, body: init?.body })
			const sent = JSON.parse(String(init?.body))
			body = ok({ appId: 'calendar', policy: { level: sent.level, setBy: 'admin', setAt: 'T', holdManual: sent.holdManual === '1' } })
		}
		return Promise.resolve(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }))
	}))
	return recorded
}

/**
 * Mounts the page with the other tabs' panels stubbed.
 */
async function mountApp () {
	const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
	await flushPromises()
	return wrapper.vm as unknown as Record<string, any>
}

afterEach(() => {
	vi.unstubAllGlobals()
	window.localStorage.clear()
})

describe('range guard and skipped versions', () => {
	it('asks once when the policy holds the install back, then retries with overrideRange', async () => {
		const installs = stubFetch([failure({ category: 'outOfRange', level: 'patch' }), ok({ installStatus: 'installed' })])
		const vm = await mountApp()
		vm.selectedApp = 'calendar'
		vm.installedVersion = '2.3.0'
		vm.selectedVersion = '3.0.0'

		const run = vm.performInstall()
		await flushPromises()
		expect(vm.isInstallConfirmOpen).toBe(true)
		expect(vm.installConfirmMessage).toContain('patch')
		vm.onInstallConfirmResolve(true)
		await run

		expect(installs.map((call) => new URL(call.url).searchParams.get('overrideRange'))).toEqual([null, '1'])
	})

	it('installs nothing more when the admin cancels the override', async () => {
		const installs = stubFetch([failure({ category: 'outOfRange', level: 'minor' })])
		const vm = await mountApp()
		vm.selectedApp = 'calendar'
		vm.installedVersion = '2.3.0'
		vm.selectedVersion = '3.0.0'

		const run = vm.performInstall()
		await flushPromises()
		vm.onInstallConfirmResolve(false)
		await run

		expect(installs).toHaveLength(1)
	})

	it('asks before installing a skipped version, naming the reason', async () => {
		const installs = stubFetch([ok({ installStatus: 'installed' })])
		const vm = await mountApp()
		vm.selectedApp = 'calendar'
		vm.installedVersion = '2.3.3'
		vm.selectedVersion = '2.3.4'
		vm.skips = { '2.3.4': { version: '2.3.4', skippedBy: 'admin', skippedAt: 'T', reason: 'breaks the import' } }

		const declined = vm.performInstall()
		await flushPromises()
		expect(vm.installConfirmMessage).toContain('breaks the import')
		vm.onInstallConfirmResolve(false)
		await declined
		expect(installs).toHaveLength(0)

		const accepted = vm.performInstall()
		await flushPromises()
		vm.onInstallConfirmResolve(true)
		await accepted
		expect(installs).toHaveLength(1)
	})

	it('offers Skip on the update badge of an app with an update, and on no other card', async () => {
		stubFetch([])
		const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true, UpdateBadgeSkip: { name: 'UpdateBadgeSkip', props: ['appId', 'version'], emits: ['changed'], template: '<div data-testid="badge-skip">{{ appId }}@{{ version }}</div>' } } } })
		await flushPromises()
		const vm = wrapper.vm as unknown as Record<string, any>
		const entry = { installedVersion: '1.0.0', newestVersion: '1.2.0', newestCompatibleVersion: '1.2.0', linesBehind: 2, updateAvailable: true, pinned: false, sourceId: 'appstore', error: null }
		vm.apps = [
			{ id: 'behind', label: 'Behind', isCore: false, installedVersion: '1.0.0', state: 'enabled' },
			{ id: 'current', label: 'Current', isCore: false, installedVersion: '2.0.0', state: 'enabled' },
		]
		vm.pendingUpdates = { behind: entry, current: { ...entry, installedVersion: '2.0.0', newestVersion: '2.0.0', newestCompatibleVersion: '2.0.0', linesBehind: 0, updateAvailable: false } }
		await flushPromises()

		expect(wrapper.find('[data-app-id="behind"] [data-testid="badge-skip"]').text()).toBe('behind@1.2.0')
		expect(wrapper.find('[data-app-id="current"] [data-testid="badge-skip"]').exists()).toBe(false)
	})

	it('re-reads the picker\'s skip marks after a badge skip of the selected app only', async () => {
		const reads = stubFetch([])
		const vm = await mountApp()
		const skipReads = (): number => (vi.mocked(fetch).mock.calls.filter(([url]) => String(url).includes('/skips'))).length
		vm.selectedApp = 'calendar'
		await flushPromises()
		const before = skipReads()

		await vm.onCardSkipChanged('other')
		expect(skipReads()).toBe(before)

		await vm.onCardSkipChanged('calendar')
		expect(skipReads()).toBe(before + 1)
		expect(reads).toHaveLength(0)
	})

	it('saves "hold manual installs" with the current level', async () => {
		const writes = stubFetch([])
		const vm = await mountApp()
		vm.policies = { calendar: { appId: 'calendar', level: 'patch', setBy: 'admin', setAt: 'T' } }

		await vm.onHoldManualChange('calendar', true)

		expect(writes).toHaveLength(1)
		expect(JSON.parse(String(writes[0].body))).toEqual({ level: 'patch', holdManual: '1' })
		expect(vm.holdManualFor('calendar')).toBe(true)
	})

	it('does not save the switch for an app without a policy', async () => {
		const writes = stubFetch([])
		const vm = await mountApp()

		await vm.onHoldManualChange('calendar', true)

		expect(writes).toHaveLength(0)
	})
})
