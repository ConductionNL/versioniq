import { flushPromises, mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Golden test: pins what performInstall leaves behind (every request it sends,
// the result panel, the error message, the selection and the dialog state) for
// each guard, dialog answer and installer answer. Set RECORD_GOLDEN=1 to
// rewrite the fixture.
// @spec openspec/specs/version-management/spec.md
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
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

const FIXTURE = resolve(process.cwd(), 'src/__golden__/performInstall.json')
const STATE_KEYS = [
	'errorMessage', 'hasInstallResult', 'lastInstallResult', 'lastInstallDebug', 'selectedApp', 'installedVersion',
	'selectedVersion', 'sourceOverride', 'availableSource', 'isInstallingVersion', 'installRequestFromVersion',
	'installRequestToVersion', 'pinOverrideAppId', 'pinOverridePinnedVersion', 'pinOverrideTargetVersion',
	'isPinOverrideDialogOpen', 'shaMismatchAppId', 'shaMismatchVersion', 'shaMismatchExpectedSha',
	'shaMismatchActualSha', 'isShaMismatchDialogOpen', 'downgradeConfirmApp', 'downgradeConfirmFromVersion',
	'downgradeConfirmToVersion', 'isDowngradeConfirmOpen',
]

type Step = ['downgrade', boolean] | ['pin', 'repin' | 'unpin' | 'cancel'] | ['sha', boolean]
type Scenario = {
	name: string
	setup: Record<string, unknown>
	answers: unknown[]
	steps?: Step[]
	password?: 'cancel'
}

const ok = (data: Record<string, unknown>) => ({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } })
const failure = (data: Record<string, unknown>, message = 'refused') => ({ ocs: { meta: { status: 'failure', statuscode: 409, message }, data } })
const pinned = failure({ category: 'pinned', pinnedVersion: '4.0.0' })
const sha = failure({ code: 'sha_mismatch', expectedSha: 'aa', actualSha: 'bb' })
const upgrade = { selectedApp: 'deck', installedVersion: '1.0.0', selectedVersion: '1.2.0' }
const downgrade = { selectedApp: 'deck', installedVersion: '2.0.0', selectedVersion: '1.2.0' }
const stale = { errorMessage: 'Old error.', hasInstallResult: true, lastInstallResult: { appId: 'old', toVersion: '0.1.0', message: 'Old.', dryRun: false, installStatus: 'installed' }, lastInstallDebug: [{ stage: 'old' }] }
const full = { fromVersion: '1.0.0', toVersion: '1.2.0', installedVersion: '1.2.0', message: 'App updated.', installStatus: 'installed', updateType: 'upgrade', debug: [{ stage: 'download', data: 1 }] }

const SCENARIOS: Scenario[] = [
	{ name: 'no app selected', setup: { ...upgrade, selectedApp: '' }, answers: [] },
	{ name: 'no version selected', setup: { ...upgrade, selectedVersion: '' }, answers: [] },
	{ name: 'already installing', setup: { ...upgrade, isInstallingVersion: true }, answers: [] },
	{ name: 'upgrade, empty payload', setup: upgrade, answers: [ok({})] },
	{ name: 'upgrade, full payload', setup: upgrade, answers: [ok(full)] },
	{ name: 'upgrade, payload names the old version as target', setup: upgrade, answers: [ok({ toVersion: '1.0.0' })] },
	{ name: 'upgrade, payload names the new version', setup: upgrade, answers: [ok({ toVersion: '1.2.0', fromVersion: null, installedVersion: null })] },
	{ name: 'upgrade, with source override', setup: { ...upgrade, sourceOverride: 'github:acme/deck', availableSource: 'github' }, answers: [ok({})] },
	{ name: 'fresh install', setup: { ...upgrade, installedVersion: '' }, answers: [ok({ toVersion: '1.2.0' })] },
	{ name: 'fresh install of a prerelease below 0', setup: { ...upgrade, installedVersion: '', selectedVersion: '0.0.0-alpha' }, answers: [ok({})] },
	{ name: 'pinned, cancel, stale state', setup: { ...upgrade, ...stale }, answers: [pinned], steps: [['pin', 'cancel']] },
	{ name: 'upgrade, stale state', setup: { ...upgrade, ...stale }, answers: [ok({})] },
	{ name: 'same version reinstall', setup: { ...upgrade, selectedVersion: '1.0.0' }, answers: [ok({ toVersion: '1.0.0' })] },
	{ name: 'failure, empty payload', setup: upgrade, answers: [failure({})] },
	{ name: 'failure, empty meta message', setup: upgrade, answers: [failure({}, '')] },
	{ name: 'failure, hint', setup: upgrade, answers: [failure({ hint: 'Free disk space.' })] },
	{ name: 'failure, default message', setup: upgrade, answers: [failure({ message: 'Install completed.' })] },
	{ name: 'failure, backend message and status', setup: upgrade, answers: [failure({ message: 'Migration failed.', installStatus: 'reverted' })] },
	{ name: 'failure, hint, message and empty status', setup: upgrade, answers: [failure({ message: 'Broke.', hint: 'Retry.', installStatus: '' })] },
	{ name: 'failure, statuscode only', setup: upgrade, answers: [{ ocs: { meta: { status: 'ok', statuscode: 500 }, data: { message: 'Server.' } } }] },
	{ name: 'failure, full payload', setup: upgrade, answers: [failure({ ...full, installStatus: 'installed-but-broken' })] },
	{ name: 'pinned, cancel', setup: upgrade, answers: [pinned], steps: [['pin', 'cancel']] },
	{ name: 'pinned, unpin, success', setup: upgrade, answers: [pinned, ok(full)], steps: [['pin', 'unpin']] },
	{ name: 'pinned, repin, failure', setup: upgrade, answers: [pinned, failure({ hint: 'Still pinned.' })], steps: [['pin', 'repin']] },
	{ name: 'pinned without version, cancel', setup: upgrade, answers: [failure({ category: 'pinned' })], steps: [['pin', 'cancel']] },
	{ name: 'pinned category on success', setup: upgrade, answers: [ok({ category: 'pinned', pinnedVersion: '4.0.0' })] },
	{ name: 'sha mismatch, decline', setup: upgrade, answers: [sha], steps: [['sha', false]] },
	{ name: 'sha mismatch, accept, success', setup: upgrade, answers: [sha, ok(full)], steps: [['sha', true]] },
	{ name: 'sha mismatch, accept, failure', setup: upgrade, answers: [sha, failure({ message: 'Corrupt.' })], steps: [['sha', true]] },
	{ name: 'sha mismatch without hashes, decline', setup: upgrade, answers: [failure({ code: 'sha_mismatch' })], steps: [['sha', false]] },
	{ name: 'sha code on success', setup: upgrade, answers: [ok({ code: 'sha_mismatch', expectedSha: 'aa' })] },
	{ name: 'pinned, unpin, sha mismatch, accept', setup: upgrade, answers: [pinned, sha, ok(full)], steps: [['pin', 'unpin'], ['sha', true]] },
	{ name: 'pinned, unpin, sha mismatch, decline', setup: upgrade, answers: [pinned, sha], steps: [['pin', 'unpin'], ['sha', false]] },
	{ name: 'downgrade, decline', setup: downgrade, answers: [ok({ orphanedMigrations: ['m1'] })], steps: [['downgrade', false]] },
	{ name: 'downgrade, accept, success', setup: downgrade, answers: [ok({}), ok({ updateType: 'downgrade', message: 'App updated.' })], steps: [['downgrade', true]] },
	{ name: 'downgrade, accept, pinned, unpin', setup: downgrade, answers: [ok({}), pinned, ok({})], steps: [['downgrade', true], ['pin', 'unpin']] },
	{ name: 'downgrade, accept, failure', setup: downgrade, answers: [ok({}), failure({ message: 'No.' })], steps: [['downgrade', true]] },
	{ name: 'network error', setup: upgrade, answers: ['throw'] },
	{ name: 'non-error rejection', setup: upgrade, answers: ['throw-string'] },
	{ name: 'network error on the pin retry', setup: upgrade, answers: [pinned, 'throw'], steps: [['pin', 'unpin']] },
	{ name: 'network error, fresh install', setup: { ...upgrade, installedVersion: '' }, answers: ['throw'] },
	{ name: 'invalid response body', setup: upgrade, answers: ['null'] },
	{ name: 'password confirmation cancelled', setup: upgrade, answers: [ok({})], password: 'cancel' },
]

type Logged = { url: string, method?: string, body?: unknown }

/**
 * Stubs fetch: install calls take the next queued answer (or reject), every
 * other call answers an empty OCS success. Every call is logged.
 *
 * @param answers The queued install answers.
 */
function stubFetch (answers: unknown[]): Logged[] {
	const log: Logged[] = []
	vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
		const url = String(input)
		log.push({ url, method: init?.method, body: init?.body })
		let body: unknown = ok({})
		if (url.includes('/install')) {
			body = answers.length > 0 ? answers.shift() : body
		}
		if (body === 'throw') {
			return Promise.reject(new Error('network down'))
		}
		if (body === 'throw-string') {
			return Promise.reject('offline')
		}
		const text = body === 'null' ? 'null' : JSON.stringify(body)
		return Promise.resolve(new Response(text, { status: 200, headers: { 'Content-Type': 'application/json' } }))
	}))
	return log
}

/**
 * Answers one open dialog.
 *
 * @param vm The mounted page.
 * @param step The dialog and the answer.
 */
function answer (vm: Record<string, any>, step: Step): void {
	if (step[0] === 'downgrade') {
		vm.onDowngradeResolved(step[1])
	} else if (step[0] === 'pin') {
		vm.onPinOverrideResolve(step[1])
	} else {
		vm.onShaMismatchResolve(step[1])
	}
}

/**
 * Runs one scenario and returns what it left behind, as JSON text.
 *
 * @param scenario The scenario.
 */
async function run (scenario: Scenario): Promise<string> {
	const log = stubFetch([...scenario.answers])
	if (scenario.password === 'cancel') {
		vi.stubGlobal('OC', { PasswordConfirmation: { requiresPasswordConfirmation: () => true, requirePasswordConfirmation: (_ok: () => void, _options: unknown, reject: (error: Error) => void) => reject(new Error('cancelled')) } })
	}
	const wrapper = mount(App, { global: { stubs: { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true } } })
	await flushPromises()
	const vm = wrapper.vm as unknown as Record<string, any>
	Object.assign(vm, scenario.setup)
	log.length = 0
	let settled = false
	const pending = vm.performInstall().then(() => {
		settled = true
	})
	for (const step of scenario.steps ?? []) {
		await flushPromises()
		answer(vm, step)
	}
	await flushPromises()
	if (settled) {
		await pending
	}
	const state: Record<string, unknown> = { settled, requests: log }
	for (const key of STATE_KEYS) {
		state[key] = vm[key]
	}
	wrapper.unmount()
	return JSON.stringify(state, (_key, inner) => (inner === undefined ? '(undefined)' : inner))
}

afterEach(() => {
	vi.unstubAllGlobals()
	window.localStorage.clear()
})

describe('performInstall golden', () => {
	const expected: Record<string, string> = process.env.RECORD_GOLDEN === '1'
		? {}
		: JSON.parse(readFileSync(FIXTURE, 'utf8')) as Record<string, string>
	const recorded: Record<string, string> = {}

	it('has a recording for every scenario', () => {
		if (process.env.RECORD_GOLDEN !== '1') {
			expect(Object.keys(expected).sort()).toEqual(SCENARIOS.map((scenario) => scenario.name).sort())
		}
	})

	for (const scenario of SCENARIOS) {
		it(scenario.name, async () => {
			const actual = await run(scenario)
			recorded[scenario.name] = actual
			if (process.env.RECORD_GOLDEN === '1') {
				writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
				return
			}
			expect(JSON.parse(actual)).toEqual(JSON.parse(expected[scenario.name] ?? 'null'))
		})
	}
})
