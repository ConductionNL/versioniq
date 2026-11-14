import type { Proposal } from './ProposedUpdatesPanel.vue'

// SPDX-License-Identifier: EUPL-1.2
// Covers the proposed updates list with approval and decline:
// @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProposedUpdatesPanel from './ProposedUpdatesPanel.vue'
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

/**
 * @param appId The app id.
 * @param status The proposal status.
 * @param extra Fields to override.
 */
function proposal (appId: string, status: Proposal['status'], extra: Partial<Proposal> = {}): Proposal {
	return {
		appId,
		fromVersion: '1.0.0',
		toVersion: '1.0.1',
		kind: 'patch',
		status,
		proposedAt: '2026-07-20T10:00:00+00:00',
		plannedFor: '2026-07-24T01:00:00+00:00',
		announcedAt: null,
		releaseNotes: '',
		approvedBy: null,
		approvedAt: null,
		declinedBy: null,
		declinedAt: null,
		declineReason: null,
		...extra,
	}
}

const listing = {
	proposals: [
		proposal('planned', 'planned', { releaseNotes: 'Fixes the export.' }),
		proposal('notice', 'in_notice'),
		proposal('waiting', 'awaiting_approval'),
		proposal('approved', 'approved', { approvedBy: 'admin' }),
		proposal('declined', 'declined', { declinedBy: 'admin', declineReason: 'breaks forms' }),
	],
	reasons: [{ appId: 'pinnedapp', reason: 'pinned' }],
	computedAt: '2026-07-23T12:00:00+00:00',
}

describe('ProposedUpdatesPanel', () => {
	beforeEach(() => {
		mockedGet.mockReset()
		mockedWrite.mockReset()
	})

	it('shows each status, the reason for apps without a proposal, and when it was computed', async () => {
		mockedGet.mockResolvedValue({ payload: listing })
		const wrapper = mount(ProposedUpdatesPanel, { props: { labels: { planned: 'Planned app' } } })
		await flushPromises()

		expect(mockedGet).toHaveBeenCalledWith('/ocs/v2.php/apps/versioniq/api/auto-update/proposals')
		const status = (appId: string) => wrapper.find(`[data-testid="proposal-${appId}"] [data-testid="proposal-status"]`).text()
		expect(wrapper.find('[data-testid="proposal-planned"]').text()).toContain('Planned app')
		expect(wrapper.find('[data-testid="proposal-planned"]').text()).toContain('1.0.0 to 1.0.1')
		expect(status('planned')).toContain('Planned')
		expect(status('planned')).toContain('planned for')
		expect(status('notice')).toContain('Announced, in the notice period')
		expect(status('waiting')).toContain('Awaiting approval since')
		expect(status('approved')).toContain('Approved by admin')
		expect(status('declined')).toContain('Declined by admin: breaks forms')
		expect(wrapper.find('[data-testid="proposal-approved"] [data-testid="proposal-approve"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="proposal-declined"] [data-testid="proposal-decline"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="proposal-reason-pinnedapp"]').text()).toContain('Pinned, so it is not updated automatically.')
		expect(wrapper.find('[data-testid="proposed-updates-computed"]').text()).toContain('Computed at the last update check')
	})

	it('shows the release notes behind a toggle', async () => {
		mockedGet.mockResolvedValue({ payload: listing })
		const wrapper = mount(ProposedUpdatesPanel)
		await flushPromises()

		expect(wrapper.find('[data-testid="proposal-notes"]').exists()).toBe(false)
		await wrapper.find('[data-testid="proposal-planned"] [data-testid="proposal-notes-toggle"]').trigger('click')
		expect(wrapper.find('[data-testid="proposal-notes"]').text()).toBe('Fixes the export.')
	})

	it('approves exactly the proposed version and reloads', async () => {
		mockedGet.mockResolvedValue({ payload: listing })
		mockedWrite.mockResolvedValue({ payload: {} })
		const wrapper = mount(ProposedUpdatesPanel)
		await flushPromises()

		await wrapper.find('[data-testid="proposal-waiting"] [data-testid="proposal-approve"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('POST', '/ocs/v2.php/apps/versioniq/api/auto-update/proposals/waiting/approve', { version: '1.0.1' })
		expect(mockedGet).toHaveBeenCalledTimes(2)
	})

	it('declines with the reason given', async () => {
		mockedGet.mockResolvedValue({ payload: listing })
		mockedWrite.mockResolvedValue({ payload: {} })
		const wrapper = mount(ProposedUpdatesPanel)
		await flushPromises()

		await wrapper.find('[data-testid="proposal-planned"] [data-testid="proposal-decline-reason"]').setValue('  waits for the migration ')
		await wrapper.find('[data-testid="proposal-planned"] [data-testid="proposal-decline"]').trigger('click')
		await flushPromises()

		expect(mockedWrite).toHaveBeenCalledWith('POST', '/ocs/v2.php/apps/versioniq/api/auto-update/proposals/planned/decline', { version: '1.0.1', reason: 'waits for the migration' })
	})

	it('shows a refusal', async () => {
		mockedGet.mockResolvedValue({ payload: listing })
		mockedWrite.mockResolvedValue({ payload: {}, error: 'There is no proposal for that version.' })
		const wrapper = mount(ProposedUpdatesPanel)
		await flushPromises()

		await wrapper.find('[data-testid="proposal-waiting"] [data-testid="proposal-approve"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="proposed-updates-error"]').text()).toBe('There is no proposal for that version.')
		expect(mockedGet).toHaveBeenCalledTimes(1)
	})

	it('says when nothing is proposed', async () => {
		mockedGet.mockResolvedValue({ payload: { proposals: [], reasons: [], computedAt: null } })
		const wrapper = mount(ProposedUpdatesPanel)
		await flushPromises()

		expect(wrapper.text()).toContain('No automatic update is proposed.')
		expect(wrapper.text()).toContain('No update check has computed proposals yet.')
	})
})

describe('ProposedUpdatesPanel with a partial answer', () => {
	it('reads a missing list as empty', async () => {
		mockedGet.mockReset()
		mockedGet.mockResolvedValue({ payload: {} as never })
		const wrapper = mount(ProposedUpdatesPanel)
		await flushPromises()

		expect(wrapper.text()).toContain('No automatic update is proposed.')
	})
})
