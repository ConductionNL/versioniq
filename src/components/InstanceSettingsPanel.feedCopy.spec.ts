// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Covers "An instance without internet imports a local copy of the advisory
// feed" on the settings page (sources-trust-audit-and-offline-feed 3.3).
// @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import InstanceSettingsPanel from './InstanceSettingsPanel.vue'
import { ocsGet, ocsUpload, ocsWrite } from '../ocs.ts'
import { feedCopyState } from '../utils/advisoryFeedCopy.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn(), ocsUpload: vi.fn() }))

const mockedGet = vi.mocked(ocsGet)
const mockedWrite = vi.mocked(ocsWrite)
const mockedUpload = vi.mocked(ocsUpload)

const DAY = 86400
const NOW = Date.UTC(2026, 10, 14, 12) / 1000

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
	advisoryFeedSource: 'remote',
	advisoryFeedCopiedAt: null as number | null,
	advisoryFeedCopyMaxAgeDays: 30,
}

async function mountPanel (overrides: Partial<typeof settings> = {}) {
	mockedGet.mockResolvedValue({ payload: { ...settings, ...overrides } } as never)
	const wrapper = mount(InstanceSettingsPanel)
	await flushPromises()
	return wrapper
}

async function chooseFile (wrapper: Awaited<ReturnType<typeof mountPanel>>, content: string) {
	const input = wrapper.get('[data-testid="setting-advisory-feed-copy-file"]')
	const file = new File([content], 'feed.json', { type: 'application/json' })
	Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
	await input.trigger('change')
	await flushPromises()
	return file
}

describe('the local advisory copy', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedWrite.mockReset()
		mockedUpload.mockReset()
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(NOW * 1000)
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('names the date of the copy and warns past 30 days', () => {
		const copiedAt = Date.UTC(2026, 9, 20, 12) / 1000
		expect(feedCopyState(copiedAt, 30, (copiedAt + 30 * DAY) * 1000, 'en-GB')).toEqual({ text: 'Local copy of 20 Oct', stale: false })
		expect(feedCopyState(copiedAt, 30, (copiedAt + 30 * DAY + 1) * 1000, 'en-GB')?.stale).toBe(true)
		expect(feedCopyState(null, 30, NOW * 1000)).toBeNull()
	})

	it('says no copy was imported, and saves the feed source with the other settings', async () => {
		mockedWrite.mockResolvedValue({ payload: { ...settings, advisoryFeedSource: 'local' } } as never)
		const wrapper = await mountPanel()

		expect(wrapper.get('[data-testid="setting-advisory-feed-copy"]').text()).toContain('No local copy has been imported.')
		await wrapper.get('[data-testid="setting-advisory-feed-source"]').setValue('local')
		await wrapper.get('[data-testid="instance-settings-save"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('PUT', '/ocs/v2.php/apps/versioniq/api/instance-settings', expect.objectContaining({ advisoryFeedSource: 'local' }))
		expect((wrapper.get('[data-testid="setting-advisory-feed-source"]').element as HTMLSelectElement).value).toBe('local')
	})

	it('shows a stale copy with its warning', async () => {
		const wrapper = await mountPanel({ advisoryFeedSource: 'local', advisoryFeedCopiedAt: NOW - 45 * DAY })

		expect(wrapper.get('[data-testid="setting-advisory-feed-copy-state"]').text()).toMatch(/^Local copy of /)
		expect(wrapper.get('[data-testid="setting-advisory-feed-copy-stale"]').text()).toBe('The local copy is older than 30 days. Import a newer one.')
	})

	it('imports the chosen file and shows the new copy', async () => {
		mockedUpload.mockResolvedValue({ payload: { copiedAt: NOW, records: 2 } } as never)
		const wrapper = await mountPanel()

		const file = await chooseFile(wrapper, '[{"ghsa_id":"GHSA-aaaa"}]')

		expect(mockedUpload).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/advisory/feed-copy', 'file', file)
		expect(wrapper.get('[data-testid="setting-advisory-feed-copy-state"]').text()).toMatch(/^Local copy of /)
		expect(wrapper.find('[data-testid="setting-advisory-feed-copy-stale"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="instance-settings-notice"]').text()).toBe('Advisory feed copy imported.')
	})

	it('shows why a broken file was refused and keeps the previous copy', async () => {
		mockedUpload.mockResolvedValue({ payload: {}, error: 'The file is not valid JSON: Syntax error' } as never)
		const copiedAt = NOW - 2 * DAY
		const wrapper = await mountPanel({ advisoryFeedCopiedAt: copiedAt })
		const before = wrapper.get('[data-testid="setting-advisory-feed-copy-state"]').text()

		await chooseFile(wrapper, '<html>')

		expect(wrapper.get('[data-testid="setting-advisory-feed-copy-error"]').text()).toBe('The file is not valid JSON: Syntax error')
		expect(wrapper.get('[data-testid="setting-advisory-feed-copy-state"]').text()).toBe(before)
		expect(wrapper.find('[data-testid="instance-settings-notice"]').exists()).toBe(false)
	})
})
