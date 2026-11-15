import { flushPromises, shallowMount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Golden test: pins what the request handlers of the Discover, Sources,
// Trusted sources and History panels leave behind (every OCS call they send,
// the panel state and the emitted events) for each guard and each answer:
// success, an OCS error, a thrown Error and a thrown non-Error. Set
// RECORD_GOLDEN=1 to rewrite the fixture.
// @spec openspec/specs/app-discovery/spec.md
// @spec openspec/specs/external-sources/spec.md
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest'
import DiscoverPanel from './DiscoverPanel.vue'
import HistoryPanel from './HistoryPanel.vue'
import SourcesPanel from './SourcesPanel.vue'
import TrustedSourcesPanel from './TrustedSourcesPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<button><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<label><slot /></label>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<select />' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<input>' } }))

vi.mock('../ocs', async (importOriginal) => ({
	...(await importOriginal<Record<string, unknown>>()),
	ocsGet: vi.fn(),
	ocsWrite: vi.fn(),
	ensurePasswordConfirmation: vi.fn(async () => undefined),
}))

const mockedOcsGet = vi.mocked(ocsGet)
const mockedOcsWrite = vi.mocked(ocsWrite)

const FIXTURE = resolve(process.cwd(), 'src/components/__golden__/panelRequests.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded: Record<string, unknown> = {}
const expected: Record<string, unknown> = RECORD ? {} : JSON.parse(readFileSync(FIXTURE, 'utf8'))

type Answer = { kind: 'ok', payload: unknown } | { kind: 'apiError', message: string } | { kind: 'throw', error: unknown }
type Vm = Record<string, any>

const ok = (payload: unknown): Answer => ({ kind: 'ok', payload })
const apiError: Answer = { kind: 'apiError', message: 'Refused by the server.' }
const thrown: Answer = { kind: 'throw', error: new Error('Network down.') }
const thrownOther: Answer = { kind: 'throw', error: 'not an Error' }

/**
 * Turns one scripted answer into what the mocked OCS helper resolves or rejects with.
 *
 * @param answer the scripted answer
 */
function respond (answer: Answer): Promise<unknown> {
	if (answer.kind === 'throw') {
		return Promise.reject(answer.error)
	}
	if (answer.kind === 'apiError') {
		return Promise.resolve({ payload: {}, error: answer.message })
	}
	return Promise.resolve({ payload: answer.payload })
}

/**
 * Converts panel state into plain JSON (Sets become sorted arrays, signals their aborted flag).
 *
 * @param value any state value or call argument
 */
function plain (value: unknown): unknown {
	if (value instanceof Set) {
		return [...value].sort()
	}
	if (typeof AbortSignal !== 'undefined' && value instanceof AbortSignal) {
		return { signalAborted: value.aborted }
	}
	if (Array.isArray(value)) {
		return value.map(plain)
	}
	if (value && typeof value === 'object') {
		return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, plain(v)]))
	}
	return value === undefined ? '(undefined)' : value
}

/**
 * Compares (or records) one case.
 *
 * @param key the case key
 * @param actual what the case produced
 */
function check (key: string, actual: unknown): void {
	const value = plain(actual)
	recorded[key] = value
	if (!RECORD) {
		expect(value).toEqual(expected[key])
	}
}

const pick = (vm: Vm, keys: string[]) => Object.fromEntries(keys.map((k) => [k, vm[k]]))

afterAll(() => {
	if (RECORD) {
		writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
	}
})

beforeEach(() => {
	mockedOcsGet.mockReset()
	mockedOcsWrite.mockReset()
})

const DISCOVER_KEYS = ['results', 'providers', 'providerErrors', 'dismissedProviderIds', 'requestError', 'hasSearched', 'isLoading']
const hit = { appId: 'deck', name: 'Deck', summary: '', iconUrl: null, homepageUrl: null, installedVersion: null, sourceCandidates: [] }
const provider = { id: 'appstore', label: 'App Store', enabled: true }

describe('DiscoverPanel runSearch', () => {
	const scenarios: Array<{ name: string, filter: string[], installedOnly: boolean, answer: Answer }> = [
		{ name: 'full payload', filter: [], installedOnly: false, answer: ok({ results: [hit], providers: [provider], errors: [{ providerId: 'gh', message: 'down' }] }) },
		{ name: 'payload without arrays', filter: ['appstore', 'gh'], installedOnly: true, answer: ok({ results: 'x', providers: null }) },
		{ name: 'empty payload', filter: ['gh'], installedOnly: false, answer: ok({}) },
		{ name: 'ocs error', filter: [], installedOnly: true, answer: apiError },
		{ name: 'thrown Error', filter: [], installedOnly: false, answer: thrown },
		{ name: 'thrown non-Error', filter: [], installedOnly: false, answer: thrownOther },
		{ name: 'abort error', filter: [], installedOnly: false, answer: { kind: 'throw', error: new DOMException('aborted', 'AbortError') } },
		{ name: 'other DOMException', filter: [], installedOnly: false, answer: { kind: 'throw', error: new DOMException('nope', 'NotFoundError') } },
	]
	for (const scenario of scenarios) {
		it(scenario.name, async () => {
			const vm = shallowMount(DiscoverPanel).vm as unknown as Vm
			vm.sourceFilter = scenario.filter
			vm.installedOnly = scenario.installedOnly
			await flushPromises()
			vm.providers = [{ id: 'old', label: 'Old', enabled: true }]
			vm.results = [{ ...hit, appId: 'stale' }]
			vm.providerErrors = [{ providerId: 'stale', message: 'stale' }]
			vm.dismissedProviderIds = new Set(['stale'])
			vm.requestError = 'stale error'
			vm.isLoading = true
			mockedOcsGet.mockImplementation(() => respond(scenario.answer) as never)
			await vm.runSearch('deck')
			await flushPromises()
			check(`discover/${scenario.name}`, { state: pick(vm, DISCOVER_KEYS), calls: mockedOcsGet.mock.calls })
		})
	}

	it('a superseded search leaves no trace', async () => {
		const vm = shallowMount(DiscoverPanel).vm as unknown as Vm
		let releaseFirst: (value: unknown) => void = () => {}
		mockedOcsGet.mockImplementationOnce(() => new Promise((resolve) => { releaseFirst = resolve }) as never)
		mockedOcsGet.mockImplementationOnce(() => respond(ok({ results: [hit], providers: [], errors: [] })) as never)
		vm.isLoading = true
		const first = vm.runSearch('dec')
		const second = vm.runSearch('deck')
		await second
		vm.isLoading = true
		releaseFirst({ payload: { results: [{ ...hit, appId: 'first' }] } })
		await first
		await flushPromises()
		check('discover/superseded', { state: pick(vm, DISCOVER_KEYS), calls: mockedOcsGet.mock.calls })
	})

	it('a superseded search that fails leaves no trace', async () => {
		const vm = shallowMount(DiscoverPanel).vm as unknown as Vm
		let failFirst: (reason: unknown) => void = () => {}
		mockedOcsGet.mockImplementationOnce(() => new Promise((_resolve, reject) => { failFirst = reject }) as never)
		mockedOcsGet.mockImplementationOnce(() => respond(ok({ results: [], providers: [], errors: [] })) as never)
		const first = vm.runSearch('dec')
		await vm.runSearch('deck')
		vm.isLoading = true
		failFirst(new DOMException('aborted', 'AbortError'))
		await first
		await flushPromises()
		check('discover/superseded failure', { state: pick(vm, DISCOVER_KEYS), calls: mockedOcsGet.mock.calls })
	})
})

const SOURCES_KEYS = ['error', 'notice', 'loading', 'currentBinding', 'owner', 'repo', 'assetPattern']

describe('SourcesPanel bind', () => {
	const base = { selectedAppId: 'deck', forge: 'github', owner: ' Conduction ', repo: ' deck ', assetPattern: ' *.zip ', forgejoConfigured: false }
	const scenarios: Array<{ name: string, setup: Record<string, unknown>, answer: Answer }> = [
		{ name: 'no app', setup: { ...base, selectedAppId: '' }, answer: ok({}) },
		{ name: 'no owner', setup: { ...base, owner: '  ' }, answer: ok({}) },
		{ name: 'no repo', setup: { ...base, repo: '' }, answer: ok({}) },
		{ name: 'forgejo without host', setup: { ...base, forge: 'forgejo' }, answer: ok({}) },
		{ name: 'forgejo with host', setup: { ...base, forge: 'forgejo', forgejoConfigured: true }, answer: ok({ sourceId: 'forgejo:Conduction/deck' }) },
		{ name: 'bound', setup: base, answer: ok({ sourceId: 'github:Conduction/deck' }) },
		{ name: 'bound, empty pattern, no source id', setup: { ...base, assetPattern: '  ' }, answer: ok({}) },
		{ name: 'ocs error', setup: base, answer: apiError },
		{ name: 'thrown Error', setup: base, answer: thrown },
		{ name: 'thrown non-Error', setup: base, answer: thrownOther },
	]
	for (const scenario of scenarios) {
		it(scenario.name, async () => {
			mockedOcsGet.mockResolvedValue({ payload: {} } as never)
			const wrapper = shallowMount(SourcesPanel, { props: { apps: [{ id: 'deck', label: 'Deck' }] } })
			await flushPromises()
			const vm = wrapper.vm as unknown as Vm
			Object.assign(vm, scenario.setup, { error: 'stale error', notice: 'stale notice', currentBinding: null })
			await flushPromises()
			mockedOcsGet.mockClear()
			mockedOcsWrite.mockImplementation(() => respond(scenario.answer) as never)
			await vm.bind()
			await flushPromises()
			check(`sources/${scenario.name}`, { state: pick(vm, SOURCES_KEYS), writes: mockedOcsWrite.mock.calls, emitted: wrapper.emitted('bound') ?? [] })
		})
	}
})

const TRUSTED_KEYS = ['error', 'notice', 'loading', 'patterns', 'owner', 'repo', 'confirmTrust']

describe('TrustedSourcesPanel addPattern', () => {
	const base = { forge: 'github', owner: ' Conduction ', repo: ' deck ', confirmTrust: true }
	const scenarios: Array<{ name: string, setup: Record<string, unknown>, answer: Answer }> = [
		{ name: 'no owner', setup: { ...base, owner: ' ' }, answer: ok({}) },
		{ name: 'not confirmed', setup: { ...base, confirmTrust: false }, answer: ok({}) },
		{ name: 'added with repo', setup: base, answer: ok({ trustedPatterns: ['github:Conduction/deck'] }) },
		{ name: 'added without repo', setup: { ...base, repo: '  ', forge: 'forgejo' }, answer: ok({ trustedPatterns: ['forgejo:Conduction/*'] }) },
		{ name: 'added, no list back', setup: base, answer: ok({ trustedPatterns: 'x' }) },
		{ name: 'ocs error', setup: base, answer: apiError },
		{ name: 'thrown Error', setup: base, answer: thrown },
		{ name: 'thrown non-Error', setup: base, answer: thrownOther },
	]
	for (const scenario of scenarios) {
		it(scenario.name, async () => {
			mockedOcsGet.mockResolvedValue({ payload: { trustedPatterns: ['github:old/*'] } } as never)
			const wrapper = shallowMount(TrustedSourcesPanel)
			await flushPromises()
			const vm = wrapper.vm as unknown as Vm
			Object.assign(vm, scenario.setup, { error: 'stale error', notice: 'stale notice' })
			mockedOcsWrite.mockImplementation(() => respond(scenario.answer) as never)
			await vm.addPattern()
			await flushPromises()
			check(`trusted/${scenario.name}`, { state: pick(vm, TRUSTED_KEYS), writes: mockedOcsWrite.mock.calls })
		})
	}
})

const HISTORY_KEYS = ['entries', 'offset', 'hasMore', 'isLoading', 'isLoadingMore', 'error', 'hasLoadedOnce']
const entry = (id: number) => ({ id, actorUid: 'admin', appId: 'deck', operation: 'install', fromVersion: null, toVersion: '1.0.0', sourceId: null, status: 'success', message: null, createdAt: '2026-01-01 10:00:00' })
const fullPage = Array.from({ length: 50 }, (_v, i) => entry(100 + i))

describe('HistoryPanel load', () => {
	const scenarios: Array<{ name: string, appId?: string, reset: boolean, answer: Answer }> = [
		{ name: 'reset, short page', reset: true, answer: ok({ entries: [entry(1), entry(2)] }) },
		{ name: 'reset, full page, scoped', appId: 'deck', reset: true, answer: ok({ entries: fullPage }) },
		{ name: 'more, short page', reset: false, answer: ok({ entries: [entry(3)] }) },
		{ name: 'more, full page, scoped', appId: 'deck', reset: false, answer: ok({ entries: fullPage }) },
		{ name: 'more, no entries list', reset: false, answer: ok({ entries: null }) },
		{ name: 'reset, ocs error', reset: true, answer: apiError },
		{ name: 'more, ocs error', reset: false, answer: apiError },
		{ name: 'reset, thrown Error', reset: true, answer: thrown },
		{ name: 'more, thrown non-Error', reset: false, answer: thrownOther },
	]
	for (const scenario of scenarios) {
		it(scenario.name, async () => {
			mockedOcsGet.mockResolvedValue({ payload: { entries: [] } } as never)
			const wrapper = shallowMount(HistoryPanel, { props: { appId: scenario.appId } })
			await flushPromises()
			const vm = wrapper.vm as unknown as Vm
			Object.assign(vm, { entries: [entry(9)], offset: 7, hasMore: true, isLoading: false, isLoadingMore: false, error: 'stale error', hasLoadedOnce: false })
			mockedOcsGet.mockReset()
			let during: unknown = null
			mockedOcsGet.mockImplementation(() => {
				during = plain(pick(vm, HISTORY_KEYS))
				return respond(scenario.answer) as never
			})
			await vm.load(scenario.reset)
			await flushPromises()
			check(`history/${scenario.name}`, { during, state: pick(vm, HISTORY_KEYS), calls: mockedOcsGet.mock.calls })
		})
	}
})
