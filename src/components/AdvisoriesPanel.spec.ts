// SPDX-License-Identifier: EUPL-1.2
// Covers "Advisories view" (#438 items 2 and 3):
// @spec openspec/specs/security-advisory-correlation/spec.md
// and "An admin dismisses an advisory" / "An admin assigns an advisory" (advisories-triage):
// @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
// @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdvisoriesPanel from './AdvisoriesPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace('%n', String(count)).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? count)),
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: { name: 'NcCheckboxRadioSwitch', props: ['modelValue'], emits: ['update:modelValue'], template: '<label><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>' },
}))
// The dialogs are their own specs; here they only need to be reachable.
vi.mock('../dialogs/DismissAdvisoryDialog.vue', () => ({ __esModule: true, default: { name: 'DismissAdvisoryDialog', props: ['open', 'appId', 'advisoryId'], emits: ['update:open', 'dismissed'], template: '<div v-if="open" data-testid="dismiss-dialog" :data-target="appId + \'|\' + advisoryId" />' } }))
vi.mock('../dialogs/AssignAdvisoryDialog.vue', () => ({ __esModule: true, default: { name: 'AssignAdvisoryDialog', props: ['open', 'appId', 'advisoryId', 'current'], emits: ['update:open', 'assigned'], template: '<div v-if="open" data-testid="assign-dialog" :data-target="appId + \'|\' + advisoryId" />' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn(), ocsWrite: vi.fn() }))
vi.mock('../utils/enableApp', () => ({ currentLoginName: () => 'bob' }))

const mockedGet = vi.mocked(ocsGet)
const mockedWrite = vi.mocked(ocsWrite)

const serverRow = {
	appId: ':server',
	installedVersion: '31.0.2',
	state: 'pinned-to-vulnerable',
	advisories: [{ id: 'GHSA-srv1', severity: 'critical', summary: 'Server hole' }],
	recommendedVersion: '31.0.5',
	error: null,
}

describe('AdvisoriesPanel', () => {
	it('shows advisories about the Nextcloud server itself, with their severity', () => {
		const wrapper = mount(AdvisoriesPanel, {
			props: { advisories: { ':server': serverRow }, freshnessLabel: 'Checked 1 hour ago.' },
		})

		const server = wrapper.find('[data-testid="advisories-server"]')
		expect(server.exists()).toBe(true)
		expect(server.text()).toContain('31.0.2')
		expect(server.text()).toContain('GHSA-srv1')
		expect(server.text()).toContain('31.0.5')
		expect(server.find('[data-testid="advisory-severity"]').text()).toBe('Critical')
		expect(server.find('[data-testid="advisory-severity"]').attributes('data-severity')).toBe('critical')
	})

	it('says so when no published advisory is about the server', () => {
		const wrapper = mount(AdvisoriesPanel, { props: { advisories: {}, freshnessLabel: '' } })

		expect(wrapper.find('[data-testid="advisories-server"]').text()).toContain('No published advisory is about this server version.')
	})

	it('lists app advisories with a severity per advisory', () => {
		const wrapper = mount(AdvisoriesPanel, {
			props: {
				advisories: {
					calendar: {
						appId: 'calendar',
						installedVersion: '5.0.0',
						state: 'advisory-available',
						advisories: [
							{ id: 'GHSA-low', severity: 'low', summary: 'Minor' },
							{ id: 'GHSA-high', severity: 'high', summary: 'Major' },
						],
						recommendedVersion: null,
						error: null,
					},
				},
				freshnessLabel: '',
			},
		})

		const rows = wrapper.findAll('[data-testid="advisories-app-row"]')
		expect(rows).toHaveLength(1)
		const levels = rows[0].findAll('[data-testid="advisory-severity"]').map((s) => s.attributes('data-severity'))
		expect(levels).toEqual(['low', 'high'])
	})

	describe('triage', () => {
		const calendar = {
			appId: 'calendar',
			installedVersion: '5.0.0',
			state: 'pinned-to-vulnerable',
			advisories: [
				{ id: 'GHSA-a', severity: 'high', summary: 'Shares' },
				{ id: 'GHSA-b', severity: 'low', summary: 'Minor' },
			],
			recommendedVersion: '5.0.1',
			error: null,
		}
		const dismissedA = { dismissal: { reason: 'not_used', comment: 'Public shares are off', by: 'alice', at: '2026-09-27T10:05:00+00:00', until: null, installedVersion: '5.0.0' } }

		beforeEach(() => {
			mockedGet.mockReset()
			mockedWrite.mockReset()
		})

		it('moves a dismissed advisory to the Dismissed list with its reason, comment and admin', () => {
			const wrapper = mount(AdvisoriesPanel, {
				props: { advisories: { calendar }, triage: { 'calendar|GHSA-a': dismissedA }, freshnessLabel: '' },
			})

			const row = wrapper.find('[data-testid="advisories-app-row"]')
			expect(row.findAll('[data-testid="advisory-item"]').map((i) => i.attributes('data-advisory'))).toEqual(['GHSA-b'])
			const dismissed = row.find('[data-testid="advisories-dismissed"]')
			expect(dismissed.text()).toContain('1 dismissed advisory')
			expect(dismissed.text()).toContain('GHSA-a')
			expect(dismissed.text()).toContain('The affected feature is not used here')
			expect(dismissed.text()).toContain('Public shares are off')
			expect(dismissed.text()).toContain('Dismissed by alice on')
		})

		it('says the server is no longer affected once its only advisory is dismissed', () => {
			const wrapper = mount(AdvisoriesPanel, {
				props: {
					advisories: { ':server': serverRow },
					triage: { ':server|GHSA-srv1': { dismissal: { ...dismissedA.dismissal, installedVersion: '31.0.2' } } },
					freshnessLabel: '',
				},
			})

			const server = wrapper.find('[data-testid="advisories-server"]')
			expect(server.text()).not.toContain('This server version is affected')
			expect(server.find('[data-testid="advisories-dismissed"]').text()).toContain('GHSA-srv1')
		})

		it('reopens a dismissed advisory and hands the new triage map up', async () => {
			mockedWrite.mockResolvedValue({ payload: { triage: {} } } as never)
			const wrapper = mount(AdvisoriesPanel, {
				props: { advisories: { calendar }, triage: { 'calendar|GHSA-a': dismissedA }, freshnessLabel: '' },
			})

			await wrapper.find('[data-testid="advisory-reopen"]').trigger('click')
			await flushPromises()

			expect(mockedWrite).toHaveBeenCalledWith('POST', '/ocs/v2.php/apps/versioniq/api/advisory-triage/reopen', { appId: 'calendar', advisoryId: 'GHSA-a' })
			expect(wrapper.emitted('update:triage')).toEqual([[{}]])
		})

		it('shows a refused reopen and emits nothing', async () => {
			mockedWrite.mockResolvedValue({ payload: {}, error: 'Forbidden' } as never)
			const wrapper = mount(AdvisoriesPanel, {
				props: { advisories: { calendar }, triage: { 'calendar|GHSA-a': dismissedA }, freshnessLabel: '' },
			})

			await wrapper.find('[data-testid="advisory-reopen"]').trigger('click')
			await flushPromises()

			expect(wrapper.find('[role="alert"]').text()).toBe('Forbidden')
			expect(wrapper.emitted('update:triage')).toBeUndefined()
		})

		it('opens the dismiss and assign dialogs for the advisory clicked', async () => {
			const wrapper = mount(AdvisoriesPanel, { props: { advisories: { calendar }, freshnessLabel: '' } })

			await wrapper.findAll('[data-testid="advisory-dismiss"]')[1].trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="dismiss-dialog"]').attributes('data-target')).toBe('calendar|GHSA-b')

			await wrapper.findAll('[data-testid="advisory-assign"]')[0].trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="assign-dialog"]').attributes('data-target')).toBe('calendar|GHSA-a')
		})

		it('shows each owner, and the filter keeps only advisories assigned to me directly or through my group', async () => {
			mockedGet.mockResolvedValue({ payload: { groups: ['ops'] } } as never)
			const deck = { ...calendar, appId: 'deck', advisories: [{ id: 'GHSA-d', severity: 'medium', summary: 'Boards' }] }
			const mail = { ...calendar, appId: 'mail', advisories: [{ id: 'GHSA-m', severity: 'medium', summary: 'Inbox' }] }
			const wrapper = mount(AdvisoriesPanel, {
				props: {
					advisories: { calendar, deck, mail },
					triage: {
						'calendar|GHSA-a': { assignee: { type: 'user', id: 'bob' } },
						'deck|GHSA-d': { assignee: { type: 'group', id: 'ops' } },
						'mail|GHSA-m': { assignee: { type: 'user', id: 'alice' } },
					},
					freshnessLabel: '',
				},
			})
			expect(wrapper.findAll('[data-testid="advisory-owner"]').map((o) => o.text())).toEqual(['Owner: bob', 'Owner: Group ops', 'Owner: alice'])

			await wrapper.find('[data-testid="advisories-only-mine"] input').setValue(true)
			await flushPromises()

			expect(mockedGet).toHaveBeenCalledWith('/ocs/v2.php/cloud/users/bob/groups')
			expect(wrapper.findAll('[data-testid="advisory-item"]').map((i) => i.attributes('data-advisory'))).toEqual(['GHSA-a', 'GHSA-d'])
		})

		it('says so when nothing is assigned to me', async () => {
			mockedGet.mockResolvedValue({ payload: { groups: [] } } as never)
			const wrapper = mount(AdvisoriesPanel, { props: { advisories: { calendar }, freshnessLabel: '' } })

			await wrapper.find('[data-testid="advisories-only-mine"] input').setValue(true)
			await flushPromises()

			expect(wrapper.findAll('[data-testid="advisories-app-row"]')).toHaveLength(0)
			expect(wrapper.text()).toContain('No app advisory is assigned to you.')
		})
	})
})

// advisories-per-app-detail 4.1: the history per app, newest first, five at a time.
// @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
describe('AdvisoriesPanel history', () => {
	const entry = (id: string, publishedAt: string | null, affectsInstalled: boolean, cveIds: string[] = []) => ({
		id, severity: 'medium', summary: 'Summary ' + id, cveIds, publishedAt, url: null, affectsInstalled,
	})
	const fixtureapp = {
		appId: 'fixtureapp',
		installedVersion: '1.0.0',
		state: 'pinned-to-vulnerable',
		advisories: [{ id: 'GHSA-new', severity: 'medium', summary: '' }],
		recommendedVersion: null,
		error: null,
		fixStatus: 'no_fix_published',
		history: [
			entry('GHSA-new', '2026-08-01T00:00:00Z', true, ['CVE-2026-1111']),
			entry('GHSA-old', '2025-11-02T00:00:00Z', false),
			entry('GHSA-3', '2025-01-01T00:00:00Z', false),
			entry('GHSA-4', '2024-01-01T00:00:00Z', false),
			entry('GHSA-5', '2023-01-01T00:00:00Z', false),
			entry('GHSA-6', null, false),
		],
	}

	function mountWith (row: Record<string, unknown>, compliance: unknown = null) {
		return mount(AdvisoriesPanel, { props: { advisories: { fixtureapp: row } as never, freshnessLabel: '', compliance: compliance as never } })
	}

	it('lists the newest first with its CVE id, date and whether it affects the installed version', () => {
		const block = mountWith(fixtureapp).find('#section-advisories-fixtureapp')
		expect(block.exists()).toBe(true)
		const entries = block.findAll('[data-testid="advisory-item"]')
		expect(entries[0].text()).toContain('CVE-2026-1111')
		expect(entries[0].find('[data-testid="advisory-date"]').text()).toBe('2026-08-01')
		expect(entries[0].find('[data-testid="advisory-affects"]').text()).toBe('Affects the installed version')
		expect(entries[1].text()).toContain('GHSA-old')
		expect(entries[1].find('[data-testid="advisory-cve"]').text()).toBe('No CVE id')
		expect(entries[1].find('[data-testid="advisory-affects"]').text()).toBe('Fixed in the installed version')
	})

	it('shows the five most recent and the rest behind Show all', async () => {
		const wrapper = mountWith(fixtureapp)
		expect(wrapper.findAll('[data-testid="advisory-item"]')).toHaveLength(5)
		const showAll = wrapper.find('[data-testid="advisories-show-all"]')
		expect(showAll.text()).toBe('Show all 6')

		await showAll.trigger('click')

		const entries = wrapper.findAll('[data-testid="advisory-item"]')
		expect(entries).toHaveLength(6)
		expect(entries[5].find('[data-testid="advisory-date"]').text()).toBe('Date unknown')
		expect(wrapper.find('[data-testid="advisories-show-all"]').exists()).toBe(false)
	})

	it.each([
		['no_fix_published', {}, 'No fixed version is published yet.'],
		['installable', { recommendedVersion: '1.0.1' }, 'Install 1.0.1 to fix it.'],
		['not_in_source', { recommendedVersion: '1.0.1' }, 'The fixed version 1.0.1 is not offered by the source this app installs from.'],
		['needs_newer_server', { recommendedVersion: '1.0.1' }, 'The fix needs a newer Nextcloud than this server runs.'],
		['installable', { recommendedVersion: '1.0.1', heldByPin: true }, 'A pin holds this app below the fix 1.0.1.'],
	])('states the fix sentence for %s', (fixStatus, extra, sentence) => {
		const wrapper = mountWith({ ...fixtureapp, fixStatus, ...extra })
		expect(wrapper.find('[data-testid="advisory-fix"]').text()).toBe(sentence)
	})

	it('shows the compliance line at the top', () => {
		const wrapper = mountWith(fixtureapp, { status: 'not_compliant', reason: null, affected: 1, bySeverity: { medium: 1 }, affectedIds: ['fixtureapp'], unreached: 0, checkedAt: 1 })
		expect(wrapper.find('[data-testid="advisories-compliance"]').text()).toBe('Not compliant: 1 app runs an affected version (1 medium)')
	})
})
