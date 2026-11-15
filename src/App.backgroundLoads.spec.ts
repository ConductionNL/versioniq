// SPDX-License-Identifier: EUPL-1.2
// Pins what the page keeps when a background list (advisories, pins, policies,
// pending updates) is refused or answers with fields left out, the label the
// version action takes for each direction, and the debug and version-range text.
// @spec openspec/specs/version-management/spec.md
// @spec openspec/specs/pending-updates/spec.md#requirement-every-app-card-shows-its-installed-version-and-whether-an-update-is-available
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

type Answer = { meta?: Record<string, unknown>, data?: unknown }

// Child components fetch their own data and are not under test.
const stubs = Object.fromEntries([
	'AdvisoriesPanel', 'AppSourceBadges', 'AutoUpdateOverview', 'CachePanel', 'ChangelogRangePanel', 'DiscoverPanel',
	'HistoryPanel', 'InstallResultNotices', 'InstanceSettingsPanel', 'IntegrationsPanel', 'PinDriftBanner', 'PolicySelector',
	'ReleaseDate', 'ServerCompatBadge', 'SourceOverrideField', 'SourcesPanel', 'TokensPanel', 'TrustedSourcesPanel',
	'VersionChangelog', 'DowngradeConfirmDialog', 'EnableAppDialog', 'PinDialog', 'PinOverrideDialog', 'ShaMismatchDialog',
].map((name) => [name, true]))

/**
 * Mounts the page with a fetch answering the route whose prefix matches the
 * path (after the app's OCS base); unrouted paths get an empty payload.
 *
 * @param routes path prefix => answer
 */
async function mountPage (routes: Record<string, Answer> = {}) {
	vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL): Promise<Response> => {
		const path = new URL(String(input), 'http://localhost').pathname.replace('/ocs/v2.php/apps/versioniq', '')
		const key = Object.keys(routes).sort((a, b) => b.length - a.length).find((k) => path.startsWith(k))
		const answer = key === undefined ? { data: path === '/api/apps' ? { apps: [] } : {} } : routes[key]
		const body = { ocs: { meta: answer.meta ?? { status: 'ok', statuscode: 200 }, data: answer.data ?? {} } }

		return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
	}))
	const wrapper = mount(App, { global: { stubs } })
	await flushPromises()

	return { wrapper, vm: wrapper.vm as unknown as Record<string, any> }
}

const refused: Answer = { meta: { status: 'failure', statuscode: 500, message: 'Backend unavailable' } }

enableAutoUnmount(afterEach)

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
	window.localStorage.clear()
})

describe('App background lists', () => {
	it('marks advisories unavailable and clears them when the request is refused', async () => {
		const { vm } = await mountPage({ '/api/advisories': refused })

		expect([vm.advisories, vm.advisoriesCheckedAt, vm.advisoriesUnavailable]).toEqual([{}, null, true])
		expect(vm.errorMessage).toBe('')
	})

	it('keeps the advisories and the check time the server reports', async () => {
		const row = { state: 'affected', count: 1 }
		const { vm } = await mountPage({ '/api/advisories': { data: { advisories: { files: row }, checkedAt: 1700000000 } } })

		expect([vm.advisories, vm.advisoriesCheckedAt, vm.advisoriesUnavailable]).toEqual([{ files: row }, 1700000000, false])
	})

	it('reads missing advisory fields as none and never checked', async () => {
		const { vm } = await mountPage({ '/api/advisories': { data: {} } })

		expect([vm.advisories, vm.advisoriesCheckedAt, vm.advisoriesUnavailable]).toEqual([{}, null, false])
	})

	it('marks pending updates unavailable and clears them when the request is refused', async () => {
		const { vm } = await mountPage({ '/api/updates': refused })

		expect([vm.pendingUpdates, vm.updatesCheckedAt, vm.updatesUnavailable]).toEqual([{}, null, true])
	})

	it('keeps the pending updates, check time and maximum lag the server reports', async () => {
		const update = { installed: '1.0.0', latest: '1.2.0' }
		const { vm } = await mountPage({ '/api/updates': { data: { updates: { files: update }, checkedAt: 1700000100, maxLinesBehind: 3 } } })

		expect([vm.pendingUpdates, vm.updatesCheckedAt, vm.maxLinesBehind, vm.updatesUnavailable]).toEqual([{ files: update }, 1700000100, 3, false])
	})

	it('reads missing update fields as none, never checked and no lag', async () => {
		const { vm } = await mountPage({ '/api/updates': { data: {} } })

		expect([vm.pendingUpdates, vm.updatesCheckedAt, vm.maxLinesBehind, vm.updatesUnavailable]).toEqual([{}, null, null, false])
	})

	it('clears the pins and says why on the console when the request is refused', async () => {
		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)
		const { vm } = await mountPage({ '/api/pins': refused })

		expect(vm.pins).toEqual({})
		expect(consoleError).toHaveBeenCalledTimes(1)
		expect(consoleError.mock.calls[0][0]).toBe('[versioniq] loadPins failed; pin badges will not render:')
		expect(consoleError.mock.calls[0][1]).toBeInstanceOf(Error)
	})

	it('keys the pins by app id', async () => {
		const pin = { appId: 'files', version: '1.0.0', pinnedBy: 'admin', pinnedAt: '2026-01-01' }
		const { vm } = await mountPage({ '/api/pins': { data: { pins: [pin] } } })

		expect(vm.pins).toEqual({ files: pin })
		expect(vm.pinFor('files')).toEqual(pin)
		expect(vm.pinFor('mail')).toBeNull()
	})

	it('clears the policies when the request is refused and leaves the defaults', async () => {
		const { vm } = await mountPage({ '/api/policies': refused })

		expect(vm.policies).toEqual({})
		expect(vm.autoUpdateTimeZone).toBe('UTC')
	})

	it('falls back to UTC when the policies carry no time zone', async () => {
		const policy = { appId: 'files', policy: 'patch' }
		const { vm } = await mountPage({ '/api/policies': { data: { policies: [policy], autoUpdateEnabled: true } } })

		expect(vm.policies).toEqual({ files: policy })
		expect(vm.autoUpdateEnabled).toBe(true)
		expect(vm.autoUpdateTimeZone).toBe('UTC')
		expect(vm.savedAutoUpdateWindow).toBe(vm.autoUpdateWindowInput)
	})
})

describe('App version action label', () => {
	it('is empty until both an app and a version are chosen', async () => {
		const { vm } = await mountPage()
		vm.selectedApp = 'files'
		await flushPromises()

		expect(vm.changeActionLabel).toBe('')
	})

	it('names the action by the direction from the installed version', async () => {
		// Safe mode would clear a downgrade target; the label is what is under test.
		window.localStorage.setItem('versioniq_safe_mode', 'false')
		const { vm } = await mountPage()
		const labelFor = async (installed: string, selected: string): Promise<string> => {
			vm.selectedApp = 'files'
			vm.installedVersion = installed
			vm.selectedVersion = selected
			await flushPromises()

			return vm.changeActionLabel
		}

		expect(await labelFor('', '1.0.0')).toBe('Install')
		expect(await labelFor('1.0.0', '1.1.0')).toBe('Update')
		expect(await labelFor('1.1.0', '1.0.0')).toBe('Degrade')
		expect(await labelFor('1.1.0', '1.1.0')).toBe('')
	})
})

describe('App debug and range text', () => {
	it('writes each scalar type as text', async () => {
		const { vm } = await mountPage()

		expect(vm.debugValueToString(null)).toBe('null')
		expect(vm.debugValueToString('plain')).toBe('plain')
		expect(vm.debugValueToString(42)).toBe('42')
		expect(vm.debugValueToString(false)).toBe('false')
		expect(vm.debugValueToString(BigInt('9007199254740993'))).toBe('9007199254740993')
		expect(vm.debugValueToString({ a: [1] })).toBe('{"a":[1]}')
	})

	it('lists a bigint inside a debug payload by its digits', async () => {
		const { vm } = await mountPage()

		expect(vm.debugToTextLines({ size: BigInt(12), name: 'x' })).toEqual(['size: 12', 'name: x'])
		expect(vm.debugToTextLines(null)).toEqual(['—'])
	})

	it('describes a patch-only step and pluralises the step count', async () => {
		const { vm } = await mountPage()

		expect(vm.versionRangeText(null)).toBe('')
		expect(vm.versionRangeText({ direction: 'upgrade', major: 0, minor: 0, patch: 1 }))
			.toBe('Upgrade stays within major/minor and changes 1 patch version step.')
		expect(vm.versionRangeText({ direction: 'downgrade', major: 0, minor: 0, patch: 3 }))
			.toBe('Downgrade stays within major/minor and changes 3 patch version steps.')
		expect(vm.versionRangeText({ direction: 'downgrade', major: 1, minor: 1, patch: 0 }))
			.toBe('Downgrade crosses 1 major and 1 minor version step.')
	})
})
