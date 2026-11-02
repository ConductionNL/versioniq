// SPDX-License-Identifier: EUPL-1.2
// Pins formatDebugLines, getVersionRangeSummary and checkVersions: every value shape, version range and versions answer is compared with a recorded golden.
// @spec openspec/specs/version-management/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import App from './App.vue'
import golden from './App.helpers.golden.json'

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

const sparse: unknown[] = [1]
sparse[3] = 'x'

const DEBUG_VALUES: Record<string, unknown> = {
	'null': null,
	'undefined': undefined,
	'string': 'hello',
	'empty string': '',
	'number': 42,
	'boolean': false,
	'bigint': 12n,
	'empty array': [],
	'flat array': [1, 'two', true, null, undefined],
	'sparse array': sparse,
	'nested array': [[1, [2, []]], { a: 1 }, {}],
	'empty object': {},
	'flat object': { a: 1, b: 'two', c: null, d: undefined, e: false },
	'nested object': { stage: 'download', data: { url: 'https://x', headers: { accept: 'json' }, list: [1, { deep: [] }] }, empty: {} },
	'debug log': [{ stage: 'requested-install', data: { appId: 'someapp', dryRun: false } }, { stage: 'checksum', data: null }],
}

const RANGES: Array<[string, string]> = [
	['', '2.0.0'], ['1.0.0', ''], ['1.0.0', '1.0.0'],
	['1.0.0', '2.0.0'], ['2.0.0', '1.0.0'], ['1.2.3', '1.2.9'], ['1.2.9', '1.2.3'],
	['1.2.0', '1.5.0'], ['1.0.0', '3.4.5'], ['3.4.5', '1.0.0'], ['1.0', '1.0.0'],
	['1.0.0-beta', '1.0.0'], ['0.9.0', '9.9.9'], ['2.1.0', '2.3.0'], ['5.0.0', '6.0.0'],
	['1.2.3', '1.5.1'], ['1.5.1', '1.2.3'], ['1.2.3', '2.2.9'],
]

const VERSION_LISTS: Record<string, string[]> = {
	'no versions': [],
	'catalogue': ['3.4.5', '3.0.0', '2.3.0', '2.2.1', '2.1.0', '2.0.0', '1.5.0', '1.2.9', '1.2.3', '1.2.0', '1.0.0', '0.9.0'],
	'sparse': ['9.9.9', '1.0.0'],
}

/**
 * Mounts the page with fetch answering an empty OCS success and the other tabs' panels stubbed.
 */
async function mountApp () {
	vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: {} } }), { status: 200, headers: { 'Content-Type': 'application/json' } }))))
	const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
	await flushPromises()
	return wrapper.vm as unknown as Record<string, any>
}

const ok = (data: unknown) => ({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } })

const VERSION_ANSWERS: Record<string, unknown> = {
	'available versions': ok({ availableVersions: [{ version: '2.0.0' }], versions: [{ version: '9.9.9' }], installedVersion: '1.0.0', sourceId: 'github:acme/app', source: 'github' }),
	'versions only': ok({ versions: [{ version: '1.5.0' }], installedVersion: null, source: 'appstore' }),
	'nothing': ok({ installedVersion: '' }),
	'with error': ok({ availableVersions: [], installedVersion: '1.0.0', error: 'Source unreachable.' }),
	'ocs failure': { ocs: { meta: { status: 'failure', statuscode: 404, message: 'App not found.' }, data: {} } },
	'network error': 'reject',
	'non-error rejection': 'reject-string',
}

/**
 * Calls checkVersions for one answer and app id and returns the page state it leaves.
 *
 * @param vm the mounted page
 * @param run the answer, app id, preserve flag and source override
 */
async function checkVersionsCase (vm: Record<string, any>, run: { answer: unknown, appId: string, preserve: boolean, source: string }) {
	const urls: string[] = []
	vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
		urls.push(String(input))
		if (run.answer === 'reject') {
			return Promise.reject(new Error('Failed to fetch'))
		}
		if (run.answer === 'reject-string') {
			return Promise.reject('offline')
		}
		return Promise.resolve(new Response(JSON.stringify(run.answer), { status: 200, headers: { 'Content-Type': 'application/json' } }))
	}))
	vm.selectedApp = run.appId
	vm.sourceOverride = run.source
	vm.versions = [{ version: 'old' }]
	vm.selectedVersion = 'old'
	vm.versionFilter = 'o'
	vm.hasCheckedVersions = true
	vm.lastInstallDebug = [{ stage: 'old' }]
	vm.lastInstallResult = { status: 'installed' }
	vm.hasInstallResult = true
	vm.errorMessage = 'old error'
	vm.availableSource = 'old source'
	vm.installedVersion = '0.1.0'
	const pending = vm.checkVersions(run.preserve)
	const during = vm.isCheckingVersions
	await pending
	const state: Record<string, unknown> = { urls, during }
	for (const key of ['versions', 'selectedVersion', 'versionFilter', 'hasCheckedVersions', 'lastInstallDebug', 'lastInstallResult', 'hasInstallResult', 'isCheckingVersions', 'errorMessage', 'availableSource', 'installedVersion']) {
		state[key] = vm[key]
	}
	return state
}

/**
 * Runs every case against the mounted page.
 */
async function record () {
	const vm = await mountApp()
	const debug: Record<string, unknown> = {}
	for (const [name, value] of Object.entries(DEBUG_VALUES)) {
		debug[name] = { lines: vm.formatDebugLines(value), text: vm.debugToTextLines(value), nested: vm.formatDebugLines(value, 2) }
	}
	const ranges: Record<string, unknown> = {}
	for (const [listName, list] of Object.entries(VERSION_LISTS)) {
		vm.versions = list.map((version) => ({ version }))
		for (const [from, to] of RANGES) {
			ranges[`${listName}: ${from || '(none)'} -> ${to || '(none)'}`] = vm.getVersionRangeSummary(from, to) ?? 'none'
		}
	}
	const checks: Record<string, unknown> = {}
	for (const [name, answer] of Object.entries(VERSION_ANSWERS)) {
		for (const preserve of [false, true]) {
			checks[`${name}, preserve ${preserve}`] = await checkVersionsCase(vm, { answer, appId: ' calendar ', preserve, source: '' })
		}
	}
	checks['source override'] = await checkVersionsCase(vm, { answer: VERSION_ANSWERS['versions only'], appId: 'notes', preserve: false, source: 'github:acme/notes' })
	checks['no app'] = await checkVersionsCase(vm, { answer: VERSION_ANSWERS.nothing, appId: '  ', preserve: false, source: '' })
	checks['no app, preserve'] = await checkVersionsCase(vm, { answer: VERSION_ANSWERS.nothing, appId: '', preserve: true, source: '' })
	vi.unstubAllGlobals()
	return { debug, ranges, checks }
}

describe('App helpers golden', () => {
	it('matches the recorded debug lines and version ranges', async () => {
		const actual = JSON.parse(JSON.stringify(await record()))
		if (process.env.RECORD_GOLDEN === '1') {
			const { writeFileSync } = await import('node:fs')
			writeFileSync(`${process.cwd()}/src/App.helpers.golden.json`, JSON.stringify(actual, null, '\t') + '\n')
			return
		}
		for (const section of ['debug', 'ranges', 'checks'] as const) {
			for (const name of Object.keys(golden[section])) {
				expect(actual[section][name], `${section} ${name}`).toEqual((golden[section] as Record<string, unknown>)[name])
			}
			expect(Object.keys(actual[section])).toEqual(Object.keys(golden[section]))
		}
	})
})
