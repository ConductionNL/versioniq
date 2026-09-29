// SPDX-License-Identifier: EUPL-1.2
// Covers "Instance settings on a page" (#438 item 7):
// @spec openspec/specs/audit-trail/spec.md
// @spec openspec/specs/external-sources/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InstanceSettingsPanel from './InstanceSettingsPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))

const mockedGet = vi.mocked(ocsGet)
const mockedWrite = vi.mocked(ocsWrite)

const settings = {
	auditRetentionDays: 365,
	auditRetentionMinDays: 30,
	auditRetentionMaxDays: 3650,
	artifactCacheKeep: 3,
	artifactCacheKeepMax: 20,
	appStoreApiBase: '',
	appStoreApiDefault: 'https://garm3.nextcloud.com/api/v1',
	githubApiBase: '',
	githubApiDefault: 'https://api.github.com',
	githubWebBase: '',
	githubWebDefault: 'https://github.com',
	advisoryFeedUrl: '',
	advisoryFeedDefault: 'https://api.github.com/repos/nextcloud/security-advisories/security-advisories',
	maxLinesBehind: null,
	maxLinesBehindMax: 10,
}

describe('InstanceSettingsPanel', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedWrite.mockReset()
	})

	it('loads the settings and shows each default next to its override field', async () => {
		mockedGet.mockResolvedValue({ payload: settings })

		const wrapper = mount(InstanceSettingsPanel)
		await flushPromises()

		expect(mockedGet).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/instance-settings')
		expect((wrapper.find('[data-testid="setting-audit-retention"]').element as HTMLInputElement).value).toBe('365')
		expect(wrapper.text()).toContain('https://garm3.nextcloud.com/api/v1')
	})

	it('saves every field through PUT /api/instance-settings', async () => {
		mockedGet.mockResolvedValue({ payload: settings })
		mockedWrite.mockResolvedValue({ payload: { ...settings, auditRetentionDays: 90, appStoreApiBase: 'https://store.example.org' } })

		const wrapper = mount(InstanceSettingsPanel)
		await flushPromises()
		await wrapper.find('[data-testid="setting-audit-retention"]').setValue('90')
		await wrapper.find('[data-testid="setting-appstore-api-base"]').setValue('https://store.example.org')
		await wrapper.find('[data-testid="instance-settings-save"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/instance-settings', expect.objectContaining({
			auditRetentionDays: '90',
			appStoreApiBase: 'https://store.example.org',
			artifactCacheKeep: '3',
		}))
		expect(wrapper.find('[data-testid="instance-settings-notice"]').exists()).toBe(true)
	})

	it('shows the server message when a value is refused', async () => {
		mockedGet.mockResolvedValue({ payload: settings })
		mockedWrite.mockResolvedValue({ payload: {}, error: 'The audit retention must be a whole number between 30 and 3650.' })

		const wrapper = mount(InstanceSettingsPanel)
		await flushPromises()
		await wrapper.find('[data-testid="instance-settings-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="instance-settings-error"]').text()).toContain('between 30 and 3650')
	})

	// inventory-pending-updates D3: the lag limit, empty for off.
	// @spec openspec/specs/pending-updates/spec.md
	it('saves the release lag limit and sends empty when the check is off', async () => {
		mockedGet.mockResolvedValue({ payload: settings })
		mockedWrite.mockResolvedValue({ payload: { ...settings, maxLinesBehind: 1 } })

		const wrapper = mount(InstanceSettingsPanel)
		await flushPromises()
		expect((wrapper.find('[data-testid="setting-max-lines-behind"]').element as HTMLInputElement).value).toBe('')
		await wrapper.find('[data-testid="setting-max-lines-behind"]').setValue('1')
		await wrapper.find('[data-testid="instance-settings-save"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/instance-settings', expect.objectContaining({ maxLinesBehind: '1' }))
		expect((wrapper.find('[data-testid="setting-max-lines-behind"]').element as HTMLInputElement).value).toBe('1')
	})
})
