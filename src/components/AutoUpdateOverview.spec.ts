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
