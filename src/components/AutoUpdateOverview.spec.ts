// SPDX-License-Identifier: EUPL-1.2
// Covers "Scheduled and blocked updates on one view" (aut-dashboard, #438):
// @spec openspec/specs/auto-update-policies/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AutoUpdateOverview from './AutoUpdateOverview.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	getDayNamesShort: () => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
	getFirstDay: () => 1,
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button type="button" @click="$emit(\'click\')"><slot /></button>' } }))

const policies = {
	calendar: { appId: 'calendar', level: 'patch', setBy: 'admin', setAt: '', blockedVersions: [] },
	deck: { appId: 'deck', level: 'minor', setBy: 'admin', setAt: '', blockedVersions: [{ version: '2.3.4', at: '2026-09-01T02:00:00+00:00' }] },
	mail: { appId: 'mail', level: 'none', setBy: 'admin', setAt: '', blockedVersions: [] },
}

describe('AutoUpdateOverview', () => {
	it('lists every app with a policy as scheduled, in the window, and every blocked version with a retry', async () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies, labels: { deck: 'Deck' }, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'Europe/Amsterdam' },
		})

		const scheduled = wrapper.findAll('[data-testid="overview-scheduled-row"]').map((row) => row.text())
		expect(scheduled).toHaveLength(2)
		expect(scheduled[0]).toContain('calendar')
		expect(scheduled[1]).toContain('Deck')
		expect(wrapper.text()).toContain('01:00-05:00')

		const blocked = wrapper.findAll('[data-testid="overview-blocked-row"]')
		expect(blocked).toHaveLength(1)
		expect(blocked[0].text()).toContain('2.3.4')
		await blocked[0].find('button').trigger('click')
		expect(wrapper.emitted('retry')).toEqual([['deck', '2.3.4']])
	})

	it('says nothing is scheduled while automatic updates are off', () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies, labels: {}, autoUpdateEnabled: false, window: '01:00-05:00', timeZone: 'UTC' },
		})

		expect(wrapper.find('[data-testid="overview-disabled"]').exists()).toBe(true)
	})
	// autoupdate-schedules task 3.3: days and next run per kind.
	// @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	it('shows the days and next run of each kind a policy reaches, and never for a kind with no days', () => {
		const schedule = {
			patch: { days: [1, 2, 3, 4, 5], monthly: false },
			minor: { days: [2], monthly: true },
			major: { days: [], monthly: false },
		}
		const nextRuns = { patch: '2026-11-16T01:00:00+01:00', minor: '2026-12-01T01:00:00+01:00', major: null }
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies: { ...policies, mail: { ...policies.mail, level: 'all' } }, labels: {}, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'Europe/Amsterdam', schedule, nextRuns },
		})

		const patch = wrapper.find('[data-testid="overview-kind-patch"]').text()
		const next = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Amsterdam' }).format(new Date('2026-11-16T01:00:00+01:00'))
		expect(patch).toBe(`Patch updates: Mon to Fri, next run ${next} (Europe/Amsterdam)`)
		expect(wrapper.find('[data-testid="overview-kind-minor"]').text()).toContain('Minor updates: Tue, the first of each month, next run')
		expect(wrapper.find('[data-testid="overview-kind-major"]').text()).toBe('Major updates: never automatically')
		expect(wrapper.find('[data-testid="overview-nothing-runs"]').exists()).toBe(false)
	})

	it('lists only the kinds the scheduled policies reach', () => {
		const schedule = { patch: { days: [1, 2, 3, 4, 5, 6, 7], monthly: false }, minor: { days: [1], monthly: false }, major: { days: [1], monthly: false } }
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies: { calendar: policies.calendar }, labels: {}, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'UTC', schedule, nextRuns: {} },
		})

		expect(wrapper.findAll('[data-testid^="overview-kind-"]').map((row) => row.text())).toEqual(['Patch updates: every day'])
	})

	it('says nothing runs when no kind has a day', () => {
		const empty = { patch: { days: [], monthly: false }, minor: { days: [], monthly: false }, major: { days: [], monthly: false } }
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies, labels: {}, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'UTC', schedule: empty, nextRuns: { patch: null, minor: null, major: null } },
		})

		expect(wrapper.find('[data-testid="overview-nothing-runs"]').text()).toBe('No kind of update is scheduled, so nothing runs.')
		expect(wrapper.find('[data-testid="overview-kind-patch"]').text()).toBe('Patch updates: never automatically')
		expect(wrapper.find('[data-testid="overview-kind-minor"]').text()).toBe('Minor updates: never automatically')
	})

	it('shows no kind lines without a schedule', () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies, labels: {}, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'UTC' },
		})

		expect(wrapper.findAll('[data-testid^="overview-kind-"]')).toHaveLength(0)
		expect(wrapper.find('[data-testid="overview-nothing-runs"]').exists()).toBe(false)
	})
})
