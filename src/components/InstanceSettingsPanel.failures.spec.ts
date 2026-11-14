// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// The instance settings page when loading or saving fails, and how the form is filled and sent.
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
	githubApiBase: 'https://ghe.example.org/api/v3',
	githubApiDefault: 'https://api.github.com',
	githubWebBase: '',
	githubWebDefault: 'https://github.com',
	advisoryFeedUrl: '',
	advisoryFeedDefault: 'https://api.github.com/repos/nextcloud/security-advisories/security-advisories',
	maxLinesBehind: 2,
	maxLinesBehindMax: 10,
}

/**
 * Mounts the panel and lets the load settle.
 */
async function mountPanel () {
	const wrapper = mount(InstanceSettingsPanel)
	await flushPromises()
	return wrapper
}

/**
 * Clicks save and lets the write settle.
 *
 * @param wrapper the panel
 */
async function save (wrapper: Awaited<ReturnType<typeof mountPanel>>): Promise<void> {
	await wrapper.find('[data-testid="instance-settings-save"]').trigger('click')
	await flushPromises()
}

/**
 * The error text the panel shows, or null.
 *
 * @param wrapper the panel
 */
function errorText (wrapper: Awaited<ReturnType<typeof mountPanel>>): string | null {
	const node = wrapper.find('[data-testid="instance-settings-error"]')
	return node.exists() ? node.text() : null
}

describe('InstanceSettingsPanel loading', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedWrite.mockReset()
	})

	it('shows the server refusal and no form', async () => {
		mockedGet.mockResolvedValue({ payload: settings, error: 'Admins only.' } as never)
		const wrapper = await mountPanel()

		expect(errorText(wrapper)).toBe('Admins only.')
		expect(wrapper.find('[data-testid="setting-audit-retention"]').exists()).toBe(false)
	})

	it('shows a thrown error message', async () => {
		mockedGet.mockRejectedValue(new Error('Network down'))
		const wrapper = await mountPanel()

		expect(errorText(wrapper)).toBe('Network down')
	})

	it('falls back to a generic message for anything else thrown', async () => {
		mockedGet.mockRejectedValue('boom')
		const wrapper = await mountPanel()

		expect(errorText(wrapper)).toBe('Could not load the settings.')
	})

	it('fills a set lines-behind limit and an override', async () => {
		mockedGet.mockResolvedValue({ payload: settings } as never)
		const wrapper = await mountPanel()

		expect((wrapper.find('[data-testid="setting-max-lines-behind"]').element as HTMLInputElement).value).toBe('2')
		expect((wrapper.find('[data-testid="setting-github-api-base"]').element as HTMLInputElement).value).toBe('https://ghe.example.org/api/v3')
		expect(errorText(wrapper)).toBeNull()
	})
})

describe('InstanceSettingsPanel saving', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedWrite.mockReset()
		mockedGet.mockResolvedValue({ payload: settings } as never)
	})

	it('sends every field as a string, an emptied limit as empty', async () => {
		mockedWrite.mockResolvedValue({ payload: settings } as never)
		const wrapper = await mountPanel()
		await wrapper.find('[data-testid="setting-advisory-feed"]').setValue('https://mirror.example.org/feed')
		await wrapper.find('[data-testid="setting-max-lines-behind"]').setValue('')

		await save(wrapper)

		expect(mockedWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/instance-settings', {
			auditRetentionDays: '365',
			artifactCacheKeep: '3',
			appStoreApiBase: '',
			githubApiBase: 'https://ghe.example.org/api/v3',
			githubWebBase: '',
			advisoryFeedUrl: 'https://mirror.example.org/feed',
			maxLinesBehind: '',
			allowUnsignedUpload: 'false',
		})
		expect(wrapper.find('[data-testid="instance-settings-notice"]').text()).toBe('Settings saved.')
	})

	it('shows the server refusal and no notice', async () => {
		mockedWrite.mockResolvedValue({ payload: settings, error: 'Retention must be at least 30 days.' } as never)
		const wrapper = await mountPanel()

		await save(wrapper)

		expect(errorText(wrapper)).toBe('Retention must be at least 30 days.')
		expect(wrapper.find('[data-testid="instance-settings-notice"]').exists()).toBe(false)
	})

	it('shows a thrown error, then the fallback, and re-enables saving', async () => {
		mockedWrite.mockRejectedValueOnce(new Error('Network down'))
		const wrapper = await mountPanel()
		await save(wrapper)
		expect(errorText(wrapper)).toBe('Network down')

		mockedWrite.mockRejectedValueOnce('boom')
		await save(wrapper)
		expect(errorText(wrapper)).toBe('Could not save the settings.')
		expect(wrapper.find('[data-testid="instance-settings-save"]').attributes('disabled')).toBeUndefined()
	})
})
