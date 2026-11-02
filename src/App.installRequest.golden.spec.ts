import { flushPromises, mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Golden test: pins the install request URL and body requestInstall builds for
// every combination of install flags, debug, dry run and source override, and
// the status label shown for every install status. Set RECORD_GOLDEN=1 to
// rewrite the fixture.
// @spec openspec/specs/version-management/spec.md
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterAll, afterEach, describe, expect, it, vi } from 'vitest'
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

const FIXTURE = resolve(process.cwd(), 'src/__golden__/installRequest.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded: Record<string, unknown> = {}
const expected: Record<string, unknown> = RECORD ? {} : JSON.parse(readFileSync(FIXTURE, 'utf8'))

/**
 * Compares (or records) one case.
 *
 * @param key the case key
 * @param actual what the case produced
 */
function check (key: string, actual: unknown): void {
	const value = JSON.parse(JSON.stringify(actual ?? null))
	recorded[key] = value
	if (!RECORD) {
		expect(value).toEqual(expected[key])
	}
}

type Recorded = { url: string, method?: string, body?: unknown }

/**
 * Stubs fetch: every call answers an empty OCS success; install calls are recorded.
 */
function stubFetch (): Recorded[] {
	const installs: Recorded[] = []
	vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
		const url = String(input)
		if (url.includes('/install')) {
			installs.push({ url, method: init?.method, body: init?.body })
		}
		const body = { ocs: { meta: { status: 'ok', statuscode: 200 }, data: { installStatus: 'installed' } } }
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

afterEach(() => {
	vi.unstubAllGlobals()
	window.localStorage.clear()
})

afterAll(() => {
	if (RECORD) {
		writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
	}
})

const FLAGS = ['overridePin', 'pinRequested', 'acceptNewSha', 'allowDowngrade', 'forceDryRun'] as const

describe('requestInstall', () => {
	it('builds the request for every flag combination', async () => {
		const installs = stubFetch()
		const vm = await mountApp()
		for (let env = 0; env < 8; env++) {
			vm.debugModeEnabled = (env & 1) === 1
			vm.dryRunEnabled = (env & 2) === 2
			vm.sourceOverride = (env & 4) === 4 ? 'https://example.test/app.tar.gz' : ''
			for (let mask = 0; mask < 2 ** FLAGS.length; mask++) {
				const request: Record<string, unknown> = { appId: 'my app', version: '1.2.0-beta+1' }
				FLAGS.forEach((flag, i) => {
					if (mask & (1 << i)) {
						request[flag] = flag === 'overridePin' ? '1.0.0' : true
					}
				})
				installs.length = 0
				const result = await vm.requestInstall(request)
				check(`env ${env} flags ${mask}`, { installs, result })
			}
		}
	})

	it('sends no flag that is explicitly false or empty', async () => {
		const installs = stubFetch()
		const vm = await mountApp()
		await vm.requestInstall({ appId: 'deck', version: '2.0.0', overridePin: '', pinRequested: false, acceptNewSha: false, allowDowngrade: false, forceDryRun: false })
		check('explicit false', installs)
	})
})

describe('installStatusLabel', () => {
	it('labels every install status', async () => {
		stubFetch()
		const vm = await mountApp()
		const statuses = [null, 'dry-run', 'reverted', 'installed-but-broken', 'failed', 'error', 'installed', 'constructor', '', undefined]
		for (const status of statuses) {
			vm.lastInstallResult = status === null ? null : { appId: 'deck', toVersion: '1.0.0', message: '', dryRun: false, installStatus: status }
			check(`label ${String(status)}`, vm.installStatusLabel)
		}
	})
})
