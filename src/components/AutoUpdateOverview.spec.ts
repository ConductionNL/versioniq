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

	// @spec openspec/changes/inventory-pins-checks-and-pending/specs/auto-update-policies/spec.md#requirement-the-overview-lists-the-updates-that-are-pending-now
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
