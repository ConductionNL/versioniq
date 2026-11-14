// SPDX-License-Identifier: EUPL-1.2
// The one-click update buttons go through the normal install path: the card
// offers "Update to {version}" from GET /api/updates, a pinned app's 409 opens
// the pin override dialog, the advisory line installs the recommended version,
// and Update all installs the ticked apps one after the other.
// @spec openspec/changes/install-one-click-updates/specs/version-management/spec.md
// @spec openspec/changes/install-one-click-updates/specs/security-advisory-correlation/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number) => (count === 1 ? one : many).replace('%n', String(count)),
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app: string, _key: string, fallback: unknown) => fallback,
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({ default: { name: 'NcCheckboxRadioSwitch', template: '<div><slot /></div>' } }))
// Open dialogs render their name and buttons, so a spec can see which one opened and drive it.
vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'buttons'],
		emits: ['update:open'],
		template: '<div v-if="open" :data-dialog="name"><slot /><button v-for="b in (buttons || [])" :key="b.label" :data-label="b.label" :disabled="b.disabled" @click="b.callback()">{{ b.label }}</button></div>',
	},
}))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcPasswordField', () => ({ default: { name: 'NcPasswordField', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcSelect', () => ({ default: { name: 'NcSelect', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcTextField', () => ({ default: { name: 'NcTextField', template: '<div><slot /></div>' } }))

const ok = (data: unknown) => ({ ocs: { meta: { status: 'ok', statuscode: 200 }, data } })
const refused = (data: Record<string, unknown>) => ({ ocs: { meta: { status: 'failure', statuscode: 409, message: 'refused' }, data } })

function entry (installedVersion: string, newest: string, pinned = false) {
  return {
	installedVersion,
	newestVersion: newest,
	newestCompatibleVersion: newest,
	linesBehind: 1,
	updateAvailable: true,
	pinned,
	sourceId: null,
	error: null,
}
}

const apps = [
	{ id: 'calendar', label: 'Calendar', isCore: false, installedVersion: '4.0.0', state: 'enabled' },
	{ id: 'hermiq', label: 'Hermiq', isCore: false, installedVersion: '1.0.0', state: 'enabled' },
	{ id: 'openregister', label: 'OpenRegister', isCore: false, installedVersion: '2.3.0', state: 'enabled' },
]

const updates = {
	calendar: entry('4.0.0', '4.1.0'),
	hermiq: entry('1.0.0', '1.2.0', true),
	openregister: entry('2.3.0', '2.4.1'),
}

const advisories = {
	openregister: {
		appId: 'openregister',
		installedVersion: '2.3.0',
		state: 'advisory-available',
		advisories: [{ id: 'GHSA-xxxx', severity: 'high' }],
		recommendedVersion: '2.3.4',
		error: null,
	},
}

/**
 * Stubs fetch: the app list, the snapshot and the advisories carry data;
 * install calls take the next queued answer for their app.
 *
 * @param installAnswers Queued OCS envelopes per app id.
 */
function stubFetch (installAnswers: Record<string, unknown[]> = {}): string[] {
	const installs: string[] = []
	vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
		const url = String(input)
		let body: unknown = ok({})
		const install = url.match(/\/api\/app\/([^/]+)\/versions\/([^/]+)\/install/)
		if (install) {
			installs.push(url.replace(/^.*\/api\/app\//, '').replace(/&format=json$/, ''))
			body = installAnswers[install[1] ?? ""]?.shift() ?? ok({ appId: install[1], fromVersion: '0', toVersion: install[2] })
		} else if (url.includes('/api/apps')) {
			body = ok({ apps })
		} else if (url.includes('/api/updates')) {
			body = ok({ updates, checkedAt: 1700000000, maxLinesBehind: null })
		} else if (url.includes('/api/advisories')) {
			body = ok({ advisories, checkedAt: 1700000000 })
		} else if (url.includes('/versions')) {
			body = ok({ installedVersion: url.includes('openregister') ? '2.3.0' : '4.0.0', availableVersions: [] })
		}
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
	return wrapper
}

afterEach(() => {
	vi.unstubAllGlobals()
	window.localStorage.clear()
})

describe('one-click updates', () => {
	it('offers "Update to {version}" from the snapshot and installs it after one confirmation', async () => {
		const installs = stubFetch()
		const wrapper = await mountApp()

		const button = wrapper.find('[data-app-id="openregister"] [data-testid="quick-update-button"]')
		expect(button.text()).toBe('Update to 2.4.1')
		await button.trigger('click')
		await flushPromises()

		const dialog = wrapper.find('[data-dialog="Update openregister"]')
		expect(dialog.text()).toContain('openregister moves from 2.3.0 to 2.4.1.')
		await dialog.find('[data-label="Update"]').trigger('click')
		await flushPromises()

		expect(installs).toHaveLength(1)
		expect(installs[0]).toMatch(/^openregister\/versions\/2\.4\.1\/install\?/)
		expect(installs[0]).not.toMatch(/overridePin|allowDowngrade/)
	})

	it('opens the pin override dialog when a pinned app answers 409', async () => {
		const installs = stubFetch({ calendar: [refused({ category: 'pinned', pinnedVersion: '4.0.0' })] })
		const wrapper = await mountApp()

		await wrapper.find('[data-app-id="calendar"] [data-testid="quick-update-button"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-dialog="Update calendar"] [data-label="Update"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-dialog="calendar is pinned"]').exists()).toBe(true)
		expect(installs).toHaveLength(1)
	})

	it('installs the recommended version from the advisory line', async () => {
		const installs = stubFetch()
		const wrapper = await mountApp()

		const button = wrapper.find('[data-app-id="openregister"] [data-testid="safe-version-button"]')
		expect(button.text()).toBe('Update to safe version 2.3.4')
		await button.trigger('click')
		await flushPromises()

		const dialog = wrapper.find('[data-dialog="Update openregister"]')
		expect(dialog.text()).toContain('This version resolves advisory GHSA-xxxx.')
		await dialog.find('[data-label="Update"]').trigger('click')
		await flushPromises()

		expect(installs).toHaveLength(1)
		expect(installs[0]).toMatch(/^openregister\/versions\/2\.3\.4\/install\?/)
	})

	it('updates all ticked apps one after the other, skips the pinned one and keeps going after a failure', async () => {
		const installs = stubFetch({ calendar: [refused({ category: 'checksum', hint: 'Re-download the release' })] })
		const wrapper = await mountApp()

		const button = wrapper.find('[data-testid="update-all-button"]')
		expect(button.text()).toBe('Update all (3)')
		await button.trigger('click')
		await flushPromises()

		const dialog = wrapper.find('[data-dialog="Update all apps"]')
		expect(dialog.find('[data-testid="update-all-row-hermiq"]').text()).toContain('Pinned to 1.0.0')
		await dialog.find('[data-label="Update 2 apps"]').trigger('click')
		await flushPromises()

		expect(installs.map((url) => url.split('/install')[0])).toEqual(['calendar/versions/4.1.0', 'openregister/versions/2.4.1'])
		const rows = wrapper.find('[data-dialog="Update all apps"]')
		expect(rows.find('[data-testid="update-all-row-calendar"]').text()).toContain('Failed (checksum): Re-download the release')
		expect(rows.find('[data-testid="update-all-row-openregister"]').text()).toContain('Updated to 2.4.1')
	})
})
