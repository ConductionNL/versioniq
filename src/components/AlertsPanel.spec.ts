// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// The Alerts tab: scope, followed apps, recent releases, webhook targets with their last delivery and Send test.
// @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md
// @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AlertsPanel from './AlertsPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', props: ['type', 'disabled', 'variant'], template: '<button :type="type ?? \'button\'" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div data-testid="note"><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))

const mockedGet = vi.mocked(ocsGet)
const mockedWrite = vi.mocked(ocsWrite)
const BASE = '/ocs/v2.php/apps/versioniq/api'
const TARGET = { id: 'a1', name: 'Ops channel', url: 'https://chat.example.org/hooks/abc', format: 'chat', kinds: ['security', 'auto_update'], enabled: true, hasSecret: false, last: null }

/**
 * Mounts the panel over a stored state.
 */
async function mountPanel () {
	mockedGet.mockImplementation(async (path: string) => path.endsWith('/webhooks')
		? { payload: { targets: [TARGET] } }
		: { payload: { scope: 'forge', releases: [{ appId: 'hermiq', version: '1.5.0', sourceId: 'github:ConductionNL/hermiq', compatible: false, detectedAt: 1 }], follows: [{ appId: 'forms', sourceId: 'appstore', addedBy: 'alice', addedAt: '' }] } })
	const wrapper = mount(AlertsPanel)
	await flushPromises()
	return wrapper
}

describe('AlertsPanel', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedWrite.mockReset()
	})

	it('shows the scope, the followed apps, the releases and the targets with their last delivery', async () => {
		const wrapper = await mountPanel()

		expect((wrapper.find('input[value="forge"]').element as HTMLInputElement).checked).toBe(true)
		expect(wrapper.findAll('[data-testid="alerts-follow-row"]').map((row) => row.text())).toEqual([expect.stringContaining('forms')])
		expect(wrapper.find('[data-testid="alerts-releases"]').text()).toContain('hermiq 1.5.0')
		expect(wrapper.find('[data-testid="alerts-releases"]').text()).toContain('It does not run on this server version.')
		expect(wrapper.find('[data-testid="alerts-target-row"]').text()).toContain('Ops channel')
		expect(wrapper.find('[data-testid="alerts-target-last"]').text()).toBe('Nothing delivered yet.')
	})

	it('stores a new scope', async () => {
		const wrapper = await mountPanel()
		mockedWrite.mockResolvedValue({ payload: { scope: 'all' } })

		await wrapper.find('input[value="all"]').trigger('change')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('PUT', `${BASE}/release-alerts/scope`, { scope: 'all' })
		expect((wrapper.find('input[value="all"]').element as HTMLInputElement).checked).toBe(true)
	})

	it('follows an app by id and unfollows one', async () => {
		const wrapper = await mountPanel()
		mockedWrite.mockResolvedValueOnce({ payload: { follow: { appId: 'deck', sourceId: null, addedBy: 'alice', addedAt: '' } } })

		await wrapper.find('[data-testid="alerts-follow-app"]').setValue(' deck ')
		await wrapper.find('[data-testid="alerts-follows"] form').trigger('submit')
		await flushPromises()
		expect(mockedWrite).toHaveBeenLastCalledWith('POST', `${BASE}/follow`, { appId: 'deck', sourceId: '' })
		expect(wrapper.findAll('[data-testid="alerts-follow-row"]')).toHaveLength(2)

		mockedWrite.mockResolvedValueOnce({ payload: { appId: 'forms' } })
		await wrapper.find('[data-testid="alerts-follow-row"] button').trigger('click')
		await flushPromises()
		expect(mockedWrite).toHaveBeenLastCalledWith('DELETE', `${BASE}/follow/forms`)
		expect(wrapper.findAll('[data-testid="alerts-follow-row"]').map((row) => row.text())).toEqual([expect.stringContaining('deck')])
	})

	it('adds a target, sends a test and shows the outcome, and removes it', async () => {
		const wrapper = await mountPanel()
		const added = { ...TARGET, id: 'b2', name: 'Monitoring', url: 'https://mon.example.org/hook', format: 'json', kinds: ['security'], hasSecret: true }
		mockedWrite.mockResolvedValueOnce({ payload: { target: added } })

		await wrapper.find('[data-testid="alerts-target-name"]').setValue('Monitoring')
		await wrapper.find('[data-testid="alerts-target-url"]').setValue('https://mon.example.org/hook')
		await wrapper.find('[data-testid="alerts-target-secret"]').setValue('s3cret')
		await wrapper.find('[data-testid="alerts-target-form"]').trigger('submit')
		await flushPromises()
		expect(mockedWrite).toHaveBeenLastCalledWith('POST', `${BASE}/webhooks`, { name: 'Monitoring', url: 'https://mon.example.org/hook', format: 'json', kinds: ['security'], secret: 's3cret', enabled: true })
		expect(wrapper.findAll('[data-testid="alerts-target-row"]')).toHaveLength(2)

		mockedWrite.mockResolvedValueOnce({ payload: { target: { ...TARGET, last: { at: '2026-11-14T10:00:00+00:00', status: 'succeeded', error: null } } } })
		await wrapper.findAll('[data-testid="alerts-target-row"]')[0].findAll('button')[0].trigger('click')
		await flushPromises()
		expect(mockedWrite).toHaveBeenLastCalledWith('POST', `${BASE}/webhooks/a1/test`)
		expect(wrapper.text()).toContain('Last delivery 2026-11-14T10:00:00+00:00: succeeded')

		mockedWrite.mockResolvedValueOnce({ payload: { id: 'b2' } })
		const row = wrapper.findAll('[data-testid="alerts-target-row"]').find((candidate) => candidate.text().includes('Monitoring'))!
		await row.findAll('button')[2].trigger('click')
		await flushPromises()
		expect(mockedWrite).toHaveBeenLastCalledWith('DELETE', `${BASE}/webhooks/b2`)
		expect(wrapper.text()).not.toContain('Monitoring')
	})

	it('shows a refusal and keeps the list unchanged', async () => {
		const wrapper = await mountPanel()
		mockedWrite.mockResolvedValueOnce({ payload: {}, error: 'Enter an https address without a username, password or query.' })

		await wrapper.find('[data-testid="alerts-target-name"]').setValue('Plain')
		await wrapper.find('[data-testid="alerts-target-url"]').setValue('http://chat.example.org')
		await wrapper.find('[data-testid="alerts-target-form"]').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-testid="note"]').text()).toBe('Enter an https address without a username, password or query.')
		expect(wrapper.findAll('[data-testid="alerts-target-row"]')).toHaveLength(1)
	})
})
