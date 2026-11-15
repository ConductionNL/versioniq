// SPDX-License-Identifier: EUPL-1.2
// "Restore installation" on the result card of an installed-but-broken install
// (safety-rollback-backup-and-preflight 3.4).
// @spec openspec/changes/safety-rollback-backup-and-preflight/specs/migration-safety/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App.vue'

vi.mock('@nextcloud/l10n', () => ({
	getCanonicalLocale: () => 'en-GB',
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

const STUBS = { CachePanel: true, SourcesPanel: true, TokensPanel: true, IntegrationsPanel: true, InstanceSettingsPanel: true, HistoryPanel: true, DiscoverPanel: true, AdvisoriesPanel: true, TrustedSourcesPanel: true, AutoUpdateOverview: true }
const broken = { appId: 'dossiq', fromVersion: '0.4.48', toVersion: '0.4.50', message: 'Finalize failed.', dryRun: false, installStatus: 'installed-but-broken' }

/**
 * Stubs fetch: the restore call gets `restore`, every other call an empty OCS success.
 *
 * @param restore The restore answer's status and data.
 * @param restore.status The HTTP status.
 * @param restore.data The OCS data.
 */
function stubFetch (restore: { status: number, data: Record<string, unknown> }): string[] {
	const calls: string[] = []
	vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
		const url = String(input)
		calls.push(`${init?.method ?? 'GET'} ${url}`)
		const isRestore = url.includes('/restore')
		const body = { ocs: { meta: { status: isRestore && restore.status >= 400 ? 'failure' : 'ok', statuscode: isRestore ? restore.status : 200 }, data: isRestore ? restore.data : {} } }
		return Promise.resolve(new Response(JSON.stringify(body), { status: isRestore ? restore.status : 200, headers: { 'Content-Type': 'application/json' } }))
	}))
	return calls
}

describe('App restore installation', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('offers a restore only for an installed-but-broken result', async () => {
		stubFetch({ status: 200, data: {} })
		const wrapper = mount(App, { global: { stubs: STUBS } })
		await flushPromises()
		const vm = wrapper.vm as unknown as Record<string, any>

		vm.lastInstallResult = broken
		expect(vm.canRestoreInstall).toBe(true)
		vm.lastInstallResult = { ...broken, installStatus: 'reverted' }
		expect(vm.canRestoreInstall).toBe(false)
		vm.lastInstallResult = { ...broken, dryRun: true }
		expect(vm.canRestoreInstall).toBe(false)
	})

	it('posts the restore and says what was put back', async () => {
		const calls = stubFetch({ status: 200, data: { appId: 'dossiq', version: '0.4.48', backupCreatedAt: '2026-10-01T09:00:00+00:00' } })
		const wrapper = mount(App, { global: { stubs: STUBS } })
		await flushPromises()
		const vm = wrapper.vm as unknown as Record<string, any>
		calls.length = 0

		await vm.restoreInstallation('dossiq')
		await flushPromises()

		expect(calls[0]).toMatch(/^POST .*\/ocs\/v2\.php\/apps\/versioniq\/api\/apps\/dossiq\/restore\?format=json$/)
		expect(vm.restoreOutcome).toBe('Restored dossiq 0.4.48 with its tables from the backup of 1 Oct 2026.')
		expect(vm.isRestoringInstall).toBe(false)
	})

	it('points to a rollback when there is no backup', async () => {
		stubFetch({ status: 409, data: { message: 'There is no backup to restore dossiq from.', code: 'no_backup' } })
		const wrapper = mount(App, { global: { stubs: STUBS } })
		await flushPromises()
		const vm = wrapper.vm as unknown as Record<string, any>

		await vm.restoreInstallation('dossiq')

		expect(vm.restoreOutcome).toBe('There is no backup to restore. Roll back to an earlier version instead.')
	})

	it('does not restore when the password confirmation is cancelled', async () => {
		const calls = stubFetch({ status: 200, data: {} })
		vi.stubGlobal('OC', { PasswordConfirmation: { requiresPasswordConfirmation: () => true, requirePasswordConfirmation: (_ok: () => void, _options: unknown, reject: (error: Error) => void) => reject(new Error('cancelled')) } })
		const wrapper = mount(App, { global: { stubs: STUBS } })
		await flushPromises()
		const vm = wrapper.vm as unknown as Record<string, any>
		calls.length = 0

		await vm.restoreInstallation('dossiq')

		expect(calls.some((call) => call.includes('/restore'))).toBe(false)
		expect(vm.restoreOutcome).toBe('Could not restore the installation.')
	})
})
