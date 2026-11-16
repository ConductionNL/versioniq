// SPDX-License-Identifier: EUPL-1.2
// Covers "Scheduled and blocked updates on one view" (aut-dashboard, #438):
// @spec openspec/specs/auto-update-policies/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AutoUpdateOverview from './AutoUpdateOverview.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
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

	// @spec openspec/specs/auto-update-policies/spec.md#requirement-the-overview-lists-the-updates-that-are-pending-now
	it('lists each pending version with its state, from the stored sweep and its time', () => {
		const update = (installedVersion: string, newest: string, pinned = false) => ({ installedVersion, newestVersion: newest, newestCompatibleVersion: newest, linesBehind: 0, updateAvailable: true, pinned, sourceId: null, error: null })
		const wrapper = mount(AutoUpdateOverview, {
			props: {
				policies: {
					dossiq: { appId: 'dossiq', level: 'patch', blockedVersions: [] },
					decidiq: { appId: 'decidiq', level: 'all', blockedVersions: [] },
				},
				labels: { dossiq: 'Dossiq', decidiq: 'Decidiq' },
				autoUpdateEnabled: true,
				window: '01:00-05:00',
				timeZone: 'Europe/Amsterdam',
				updates: { dossiq: update('0.4.49', '0.4.50'), decidiq: update('0.9.3', '0.10.1', true) },
				updatesCheckedLabel: 'Updates checked today at 06:02',
			},
		})

		const rows = wrapper.findAll('[data-testid="overview-pending-row"]').map((row) => row.text())
		expect(rows).toEqual(['Decidiq0.10.1Held by the pin', 'Dossiq0.4.50Scheduled for the next window'])
		expect(wrapper.find('[data-testid="overview-pending-checked"]').text()).toBe('Updates checked today at 06:02')
	})

	it('says when no app with a policy has a newer version', () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies, labels: {}, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'UTC' },
		})

		expect(wrapper.find('[data-testid="overview-pending-none"]').exists()).toBe(true)
	})
})

// @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
describe('AutoUpdateOverview security holds', () => {
	const securityPolicies = {
		deck: { appId: 'deck', level: 'security', setBy: 'admin', setAt: '', blockedVersions: [] },
		calendar: { appId: 'calendar', level: 'security', setBy: 'admin', setAt: '', blockedVersions: [] },
	}

	it('says why a security fix was not installed', () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies: securityPolicies, labels: { deck: 'Deck' }, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'UTC', securityHolds: { deck: 'needs_major', calendar: 'stale' }, advisoriesFresh: true },
		})

		const held = wrapper.findAll('[data-testid="overview-held-row"]').map((row) => row.text())
		expect(held).toEqual([
			'The security fix for calendar waits until advisories are checked again.',
			'The security fix for Deck needs a major update, so it was not installed.',
		])
		expect(wrapper.find('[data-testid="overview-stale"]').exists()).toBe(false)
	})

	it('says advisories were not checked recently while an app is on security', () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies: securityPolicies, labels: {}, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'UTC', advisoriesFresh: false },
		})

		expect(wrapper.find('[data-testid="overview-stale"]').text()).toBe('Advisories were not checked recently, so security fixes wait.')
	})

	it('says nothing about stale advisories when no app is on security', () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies, labels: {}, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'UTC', advisoriesFresh: false },
		})

		expect(wrapper.find('[data-testid="overview-stale"]').exists()).toBe(false)
		expect(wrapper.findAll('[data-testid="overview-held-row"]')).toHaveLength(0)
	})

	it('names every kind the patch policy sets to by hand', () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies, labels: {}, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'UTC', manualKinds: ['patch'] },
		})

		expect(wrapper.findAll('[data-testid="overview-by-hand"]').map((row) => row.text())).toEqual([
			'Patch updates are set to by hand in the patch policy, so no app gets them automatically.',
		])
	})
})

// @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
describe('AutoUpdateOverview minimum age', () => {
	const plan = (overrides: Record<string, unknown> = {}) => ({
		state: 'planned',
		minAgeDays: 7,
		next: '2.3.1',
		heldBack: [{ version: '2.3.2', releasedAt: '2026-07-20T00:00:00+00:00', eligibleAt: '2026-07-27T00:00:00+00:00', reason: 'minimum_age' }],
		securityBypass: null,
		exception: null,
		...overrides,
	})
	const mountWith = (record: Record<string, unknown>, planCheckedAt: number | null = 1_784_772_000) => mount(AutoUpdateOverview, {
		props: {
			policies: { openregister: { appId: 'openregister', level: 'patch', blockedVersions: [], ...record } },
			labels: { openregister: 'OpenRegister' },
			autoUpdateEnabled: true,
			window: '01:00-05:00',
			timeZone: 'UTC',
			planCheckedAt,
		},
	})

	it('shows the next update and each held version with its date and a Release now', async () => {
		const wrapper = mountWith({ plan: plan() })

		expect(wrapper.find('[data-testid="overview-next"]').text()).toBe('Next update: 2.3.1')
		const held = wrapper.findAll('[data-testid="overview-held-back"]')
		expect(held).toHaveLength(1)
		expect(held[0].text()).toContain('2.3.2 held back until')
		expect(held[0].text()).toContain(new Date('2026-07-27T00:00:00+00:00').toLocaleDateString())
		expect(held[0].text()).toContain('(minimum age 7 days)')
		await held[0].find('button').trigger('click')
		expect(wrapper.emitted('release')).toEqual([['openregister', '2.3.2']])
		expect(wrapper.find('[data-testid="overview-plan-checked"]').text()).toContain('Next updates worked out at')
	})

	it('shows an exception with a Withdraw, and no Release now for that version', async () => {
		const wrapper = mountWith({ plan: plan(), minAgeException: { version: '2.3.2', by: 'alice', at: '2026-07-21T00:00:00+00:00' } })

		expect(wrapper.find('[data-testid="overview-held-back"] button').exists()).toBe(false)
		const exception = wrapper.find('[data-testid="overview-exception"]')
		expect(exception.text()).toContain('2.3.2 is released before the minimum age.')
		await exception.find('button').trigger('click')
		expect(wrapper.emitted('withdraw')).toEqual([['openregister', '2.3.2']])
	})

	it('says when no update qualifies, and why a security fix is not installed', () => {
		const wrapper = mountWith({ plan: plan({ next: null, heldBack: [], securityBypass: { version: '3.0.1', advisories: ['GHSA-9'], reason: 'outside_level' } }) })

		expect(wrapper.find('[data-testid="overview-next"]').text()).toBe('No update qualifies')
		expect(wrapper.find('[data-testid="overview-bypass"]').text()).toBe('Security fix 3.0.1 is outside the update policy, so it is not installed automatically.')
	})

	it('says plainly when the plan was never worked out', () => {
		const wrapper = mountWith({}, null)

		expect(wrapper.find('[data-testid="overview-next"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="overview-plan-checked"]').text()).toBe('The next update of each app has not been worked out yet.')
	})
})
