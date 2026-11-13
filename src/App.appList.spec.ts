// SPDX-License-Identifier: EUPL-1.2
// Pins what the app cards show (state, pin, advisory and update badges, the
// fallback icon), how the filters narrow them, and what each card button
// does: choose the app, enable it, retry a failed automatic update, and roll
// back to the last known good version.
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

const APPS = [
	{ id: 'mail', label: 'Mail', summary: 'Mail client', description: '', preview: '', isCore: false, installedVersion: '3.0.0', state: 'disabled' },
	{ id: 'files', label: 'Files', summary: '', description: 'Core files', preview: '', isCore: true, installedVersion: '30.0.0', state: 'enabled' },
	{ id: 'deck', label: 'Deck', summary: '', description: '', preview: '/deck.svg', isCore: false, installedVersion: '1.2.0', state: 'enabled', lkg: { version: '1.0.0' } },
	{ id: 'blank', label: '   ', summary: '', description: '', preview: '', isCore: false, installedVersion: null, state: 'notInstalled' },
]
const PINS = [
	{ appId: 'deck', version: '1.2.0', pinnedBy: 'admin', pinnedAt: '2026-01-02', reason: 'LTS only' },
	{ appId: 'mail', version: '3.0.0', pinnedBy: 'root', pinnedAt: '2026-02-03' },
]
const ADVISORIES = {
	deck: { appId: 'deck', installedVersion: '1.2.0', state: 'pinned-to-vulnerable', advisories: [{ id: 'GHSA-1', severity: 'low', summary: 'Stored XSS' }, { id: 'GHSA-2', severity: 'critical', summary: 'RCE' }], recommendedVersion: '1.3.1', error: null },
	mail: { appId: 'mail', installedVersion: '3.0.0', state: 'none', advisories: [], recommendedVersion: null, error: 'Feed unreachable' },
}
const UPDATES = {
	deck: { updateAvailable: true, newestCompatibleVersion: '1.4.0', linesBehind: 2 },
	mail: { error: 'Source timed out' },
}
const LISTS: Record<string, Answer> = {
	'/api/apps': { data: { apps: APPS } },
	'/api/pins': { data: { pins: PINS } },
	'/api/advisories': { data: { advisories: ADVISORIES, checkedAt: null } },
	'/api/updates': { data: { updates: UPDATES, checkedAt: null, maxLinesBehind: 1 } },
	'/api/policies': { data: { policies: [{ appId: 'deck', level: 'patch', setBy: 'admin', setAt: '', blockedVersions: [{ version: '1.2.1', reason: 'failed' }, { version: '1.2.2', reason: 'failed' }] }] } },
}

const card = (wrapper: Awaited<ReturnType<typeof mountPage>>['wrapper'], id: string) => wrapper.find(`[data-app-id="${id}"]`)
const cardIds = (wrapper: Awaited<ReturnType<typeof mountPage>>['wrapper']) => wrapper.findAll('[data-app-id]').map((element) => element.attributes('data-app-id'))

enableAutoUnmount(afterEach)

afterEach(() => {
	vi.unstubAllGlobals()
	delete (window as Window & { OC?: unknown }).OC
})

describe('App card badges', () => {
	it('names the state of an app that is disabled or not installed, and nothing for an enabled one', async () => {
		const { wrapper } = await mountPage(LISTS)

		expect(card(wrapper, 'mail').find('[data-testid="app-state-badge"]').text()).toBe('Disabled')
		expect(card(wrapper, 'blank').find('[data-testid="app-state-badge"]').text()).toBe('Not installed')
		expect(card(wrapper, 'deck').find('[data-testid="app-state-badge"]').exists()).toBe(false)
	})

	it('shows who pinned which version, with the reason when one was given', async () => {
		const { wrapper } = await mountPage(LISTS)

		const deckPin = card(wrapper, 'deck').find('[data-testid="pin-badge"]')
		expect(deckPin.text()).toBe('📌 Pinned 1.2.0')
		expect(deckPin.attributes('title')).toBe('Pinned by admin on 2026-01-02 — LTS only')
		expect(card(wrapper, 'mail').find('[data-testid="pin-badge"]').attributes('title')).toBe('Pinned by root on 2026-02-03')
		expect(card(wrapper, 'files').find('[data-testid="pin-badge"]').exists()).toBe(false)
	})

	it('names the highest advisory severity and the safe version for a vulnerable pin', async () => {
		const { wrapper } = await mountPage(LISTS)
		const deck = card(wrapper, 'deck')

		expect(deck.text()).toContain('⚠ Vulnerable version, Critical')
		expect(deck.text()).toContain('GHSA-1')
		expect(deck.text()).toContain('safe version: 1.3.1')
	})

	it('flags an app whose advisories could not be checked instead of showing a clean card', async () => {
		const { wrapper } = await mountPage(LISTS)

		const unchecked = card(wrapper, 'mail').find('[data-testid="advisory-unchecked-badge"]')
		expect(unchecked.text()).toBe('Advisories not checked')
		expect(unchecked.attributes('title')).toBe('Feed unreachable')
	})

	it('shows the update badge, or that updates were not checked', async () => {
		const { wrapper } = await mountPage(LISTS)

		expect(card(wrapper, 'deck').find('[data-testid="update-badge"]').text()).toBe('Update available: 1.4.0')
		const mailBadge = card(wrapper, 'mail').find('[data-testid="update-badge"]')
		expect(mailBadge.text()).toBe('Updates not checked')
		expect(mailBadge.attributes('title')).toBe('Source timed out')
		expect(card(wrapper, 'deck').find('[data-testid="update-policy-flag"]').exists()).toBe(true)
		expect(card(wrapper, 'mail').find('[data-testid="update-policy-flag"]').exists()).toBe(false)
	})

	it('falls back to the first letter, or "?" for a blank name, when there is no icon', async () => {
		const { wrapper } = await mountPage(LISTS)

		expect(card(wrapper, 'deck').find('img').attributes()).toMatchObject({ src: '/deck.svg', alt: 'Deck icon' })
		expect(card(wrapper, 'mail').find('[aria-hidden="true"]').text()).toBe('M')
		expect(card(wrapper, 'blank').find('[aria-hidden="true"]').text()).toBe('?')
	})

	it('describes a card by its summary, then its description, then a placeholder', async () => {
		const { wrapper } = await mountPage(LISTS)

		expect(card(wrapper, 'mail').text()).toContain('Mail client')
		expect(card(wrapper, 'files').text()).toContain('Core files')
		expect(card(wrapper, 'deck').text()).toContain('No description available.')
	})
})

describe('App card filters', () => {
	it('toggles the filter panel and hides the core apps on request', async () => {
		const { wrapper } = await mountPage(LISTS)
		const toggle = wrapper.findAll('button').find((button) => button.text() === 'Show filters')!

		await toggle.trigger('click')
		expect(toggle.text()).toBe('Hide filters')
		await wrapper.find('select').setValue('hide')

		expect(cardIds(wrapper)).toEqual(['mail', 'deck', 'blank'])
		await toggle.trigger('click')
		expect(wrapper.find('[data-testid="updates-filter"]').exists()).toBe(false)
	})

	it('narrows to apps with an update, and to apps outside the update policy', async () => {
		const { wrapper } = await mountPage(LISTS)
		await wrapper.findAll('button').find((button) => button.text() === 'Show filters')!.trigger('click')

		await wrapper.find('[data-testid="updates-filter"]').setValue('update')
		expect(cardIds(wrapper)).toEqual(['deck'])

		await wrapper.find('[data-testid="updates-filter"]').setValue('outsidePolicy')
		expect(cardIds(wrapper)).toEqual(['deck'])
	})

	it('searches the label and id, case-insensitively, and says when nothing matches', async () => {
		const { wrapper } = await mountPage(LISTS)
		const search = wrapper.find('#app-filter')

		await search.setValue('  MAI ')
		expect(cardIds(wrapper)).toEqual(['mail'])

		await search.setValue('nothing-like-this')
		expect(cardIds(wrapper)).toEqual([])
		expect(wrapper.text()).toContain('No apps match your filter.')
	})
})

describe('App card buttons', () => {
	it('offers "Choose app" only on non-core apps and loads the chosen app\'s versions', async () => {
		const { wrapper, vm, calls } = await mountPage({ ...LISTS, '/api/app/mail/versions': { data: { versions: [{ version: '3.1.0' }], installedVersion: '3.0.0', source: 'appstore' } } })
		expect(card(wrapper, 'files').findAll('button').map((button) => button.text())).not.toContain('Choose app')
		calls.length = 0

		await card(wrapper, 'mail').findAll('button').find((button) => button.text() === 'Choose app')!.trigger('click')
		await flushPromises()

		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['GET /api/app/mail/versions'])
		expect([vm.selectedApp, vm.installedVersion, vm.availableSource]).toEqual(['mail', '3.0.0', 'appstore'])
		expect(vm.versions).toEqual([{ version: '3.1.0' }])
	})

	it('ignores a pick while versions are still loading', async () => {
		const { vm, calls } = await mountPage(LISTS)
		vm.isCheckingVersions = true
		calls.length = 0

		await vm.onPickApp('mail')

		expect(calls).toEqual([])
		expect(vm.selectedApp).toBe('')
	})

	it('opens the enable dialog for a disabled app only', async () => {
		const { wrapper, vm } = await mountPage(LISTS)
		vm.enableAppError = 'stale'
		expect(card(wrapper, 'deck').find('[data-testid="app-enable-button"]').exists()).toBe(false)

		await card(wrapper, 'mail').find('[data-testid="app-enable-button"]').trigger('click')

		const dialog = wrapper.findComponent({ name: 'EnableAppDialog' })
		expect(dialog.props()).toMatchObject({ open: true, appId: 'mail', error: '' })
		dialog.vm.$emit('update:open', false)
		await flushPromises()
		expect(vm.isEnableDialogOpen).toBe(false)
	})

	it('enables with the password as Basic credentials of the current user, then reloads the app list', async () => {
		(window as Window & { OC?: unknown }).OC = { getCurrentUser: () => ({ uid: 'admin' }) }
		const { wrapper, vm, calls } = await mountPage(LISTS)
		await card(wrapper, 'mail').find('[data-testid="app-enable-button"]').trigger('click')
		calls.length = 0

		wrapper.findComponent({ name: 'EnableAppDialog' }).vm.$emit('confirm', 'secret')
		await flushPromises()

		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['POST /ocs/v2.php/cloud/apps/mail', 'GET /api/apps'])
		expect(calls[0].headers.Authorization).toBe(`Basic ${btoa('admin:secret')}`)
		expect([vm.isEnableDialogOpen, vm.isEnablingApp, vm.enableAppError]).toEqual([false, false, ''])
	})

	it('keeps the dialog open with the refusal when enabling fails', async () => {
		const { wrapper, calls } = await mountPage({ ...LISTS, 'POST /ocs/v2.php/cloud/apps/mail': refused('Wrong password') })
		await card(wrapper, 'mail').find('[data-testid="app-enable-button"]').trigger('click')
		calls.length = 0

		wrapper.findComponent({ name: 'EnableAppDialog' }).vm.$emit('confirm', 'nope')
		await flushPromises()

		expect(calls.map((call) => call.path)).toEqual(['/ocs/v2.php/cloud/apps/mail'])
		expect(calls[0].headers.Authorization).toBe(`Basic ${btoa(':nope')}`)
		expect(wrapper.findComponent({ name: 'EnableAppDialog' }).props()).toMatchObject({ open: true, error: 'Wrong password', busy: false })
	})

	it('clears a failed automatic-update attempt and drops it from the blocked list', async () => {
		const { wrapper, vm, calls } = await mountPage(LISTS)
		calls.length = 0

		card(wrapper, 'deck').findComponent({ name: 'PolicySelector' }).vm.$emit('retry', 'deck', '1.2.1')
		await flushPromises()

		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['DELETE /api/app/deck/attempts/1.2.1'])
		expect(vm.policies.deck.blockedVersions).toEqual([{ version: '1.2.2', reason: 'failed' }])
		expect(card(wrapper, 'deck').findComponent({ name: 'PolicySelector' }).props('blockedVersions')).toEqual([{ version: '1.2.2', reason: 'failed' }])
		expect(vm.isSavingPolicy).toBe(false)
	})

	it('retries from the overview too, leaving the policies alone for an app without one', async () => {
		const { wrapper, vm, calls } = await mountPage(LISTS)
		const before = vm.policies
		calls.length = 0

		wrapper.findComponent({ name: 'AutoUpdateOverview' }).vm.$emit('retry', 'mail', '3.1.0')
		await flushPromises()

		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['DELETE /api/app/mail/attempts/3.1.0'])
		expect(vm.policies).toEqual(before)
	})

	it('shows the refusal when clearing the attempt fails, and ignores a retry while saving', async () => {
		const { vm, calls } = await mountPage({ ...LISTS, 'DELETE /api/app/deck/attempts': refused('Not allowed') })
		calls.length = 0

		await vm.onRetryAttempt('deck', '1.2.1')
		expect(vm.errorMessage).toBe('Not allowed')
		expect(vm.policies.deck.blockedVersions).toHaveLength(2)

		vm.isSavingPolicy = true
		await vm.onRetryAttempt('deck', '1.2.1')
		expect(calls).toHaveLength(1)
	})

	it('rolls back to the last known good version through the downgrade confirmation', async () => {
		const { wrapper, vm, calls } = await mountPage({
			...LISTS,
			'/api/app/deck/versions': { data: { versions: [{ version: '1.2.0' }, { version: '1.0.0' }], installedVersion: '1.2.0', sourceId: 'appstore' } },
			'POST /api/app/deck/versions/1.0.0/install': { data: { orphanedMigrations: ['Version1002Date'] } },
		})
		const rollback = card(wrapper, 'deck').findAll('button').find((button) => button.text() === 'Roll back to 1.0.0')!
		expect(card(wrapper, 'mail').text()).not.toContain('Roll back')
		calls.length = 0

		await rollback.trigger('click')
		await flushPromises()

		expect(calls.map((call) => `${call.method} ${call.path}`)).toEqual(['GET /api/app/deck/versions', 'POST /api/app/deck/versions/1.0.0/install'])
		expect(calls[1].query).toEqual({ debug: '0', dryRun: '1', targetVersion: '1.0.0' })
		expect(wrapper.findComponent({ name: 'DowngradeConfirmDialog' }).props()).toMatchObject({ open: true, appId: 'deck', fromVersion: '1.2.0', toVersion: '1.0.0', orphanedMigrations: ['Version1002Date'] })
		// Safe mode would otherwise clear a selected downgrade.
		expect(vm.selectedVersion).toBe('1.0.0')

		wrapper.findComponent({ name: 'DowngradeConfirmDialog' }).vm.$emit('resolve', false)
		await flushPromises()
		expect(calls).toHaveLength(2)
		expect(vm.isDowngradeConfirmOpen).toBe(false)
	})

	it('does not roll back while an install is running', async () => {
		const { vm, calls } = await mountPage(LISTS)
		vm.isInstallingVersion = true
		calls.length = 0

		await vm.rollbackToLastKnownGood('deck', '1.0.0')

		expect(calls).toEqual([])
		expect(vm.selectedApp).toBe('')
	})
})
