// SPDX-License-Identifier: EUPL-1.2
// Pins how the page saves or clears an app's auto-update policy.
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

type Call = { method: string, path: string, body: unknown }

/**
 * A fetch that records every call and answers the policy calls as `answer` says.
 *
 * @param calls Where the calls are recorded.
 * @param answer 'ok' or 'fail'.
 */
function policyFetch (calls: Call[], answer: string) {
	return (input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> => {
		const url = new URL(String(input), 'http://localhost')
		const path = url.pathname.replace('/ocs/v2.php/apps/versioniq', '') + url.search.replace(/[?&]format=json/, '')
		calls.push({ method: init.method ?? 'GET', path, body: init.body === undefined ? null : JSON.parse(String(init.body)) })
		const isPolicy = path.includes('/policy')
		const failed = isPolicy && answer === 'fail'
		const data = path.includes('/api/apps') ? { apps: [] } : (isPolicy ? { appId: 'calendar', policy: { level: 'patch', since: 'x' } } : {})
		const meta = failed ? { status: 'failure', statuscode: 403, message: 'Not allowed' } : { status: 'ok', statuscode: 200 }

		return Promise.resolve(new Response(JSON.stringify({ ocs: { meta, data } }), {
			status: failed ? 403 : 200,
			headers: { 'Content-Type': 'application/json' },
		}))
	}
}

const stubs = { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true }

/**
 * Mount the page, then change one policy.
 *
 * @param answer How the policy call answers.
 * @param change The app and level to set.
 */
async function changePolicy (answer: string, change: { appId: string, level: string, before?: Record<string, unknown>, busy?: boolean }) {
	const calls: Call[] = []
	vi.stubGlobal('fetch', vi.fn(policyFetch(calls, answer)))
	const wrapper = mount(App, { global: { stubs } })
	await flushPromises()
	const vm = wrapper.vm as unknown as Record<string, unknown> & { onPolicyChange: (appId: string, level: string) => Promise<void> }
	vm.policies = change.before ?? { calendar: { appId: 'calendar', level: 'minor' }, notes: { appId: 'notes', level: 'patch' } }
	vm.isSavingPolicy = change.busy === true
	calls.length = 0
	await vm.onPolicyChange(change.appId, change.level)
	await flushPromises()

	return { calls, policies: vm.policies, errorMessage: vm.errorMessage, saving: vm.isSavingPolicy }
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('App onPolicyChange', () => {
	it('clears a policy with a DELETE and drops it from the list', async () => {
		const result = await changePolicy('ok', { appId: 'calendar', level: 'none' })

		expect(result.calls).toEqual([{ method: 'DELETE', path: '/api/app/calendar/policy', body: null }])
		expect(result.policies).toEqual({ notes: { appId: 'notes', level: 'patch' } })
		expect([result.errorMessage, result.saving]).toEqual(['', false])
	})

	it('sets a policy with a PUT and stores what the server answered under the app id', async () => {
		const result = await changePolicy('ok', { appId: 'calendar', level: 'patch' })

		expect(result.calls).toEqual([{ method: 'PUT', path: '/api/app/calendar/policy?level=patch', body: { level: 'patch' } }])
		expect(result.policies).toEqual({ calendar: { appId: 'calendar', level: 'patch', since: 'x' }, notes: { appId: 'notes', level: 'patch' } })
		expect([result.errorMessage, result.saving]).toEqual(['', false])
	})

	it('encodes the app id in the path', async () => {
		const result = await changePolicy('ok', { appId: 'a b', level: 'none', before: {} })

		expect(result.calls[0].path).toBe('/api/app/a%20b/policy')
	})

	it('keeps the list and shows the error when the server refuses', async () => {
		const before = { calendar: { appId: 'calendar', level: 'minor' } }
		const cleared = await changePolicy('fail', { appId: 'calendar', level: 'none', before })
		const set = await changePolicy('fail', { appId: 'calendar', level: 'patch', before })

		expect([cleared.policies, cleared.saving]).toEqual([before, false])
		expect([set.policies, set.saving]).toEqual([before, false])
		expect(cleared.errorMessage).toBe('Not allowed')
		expect(set.errorMessage).toBe('Not allowed')
	})

	it('does nothing while another policy save runs', async () => {
		const result = await changePolicy('ok', { appId: 'calendar', level: 'none', busy: true })

		expect(result.calls).toEqual([])
		expect(result.saving).toBe(true)
	})
})
