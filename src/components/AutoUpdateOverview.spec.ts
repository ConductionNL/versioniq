// SPDX-License-Identifier: EUPL-1.2
// Covers "Scheduled and blocked updates on one view" (aut-dashboard, #438):
// @spec openspec/specs/auto-update-policies/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AutoUpdateOverview from './AutoUpdateOverview.vue'

vi.mock('@nextcloud/l10n', () => {
	const fill = (text: string, vars: Record<string, unknown>) => text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? ''))
	return {
		t: (_app: string, text: string, vars: Record<string, unknown> = {}) => fill(text, vars),
		n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) => fill(count === 1 ? one : many, vars).replace('%n', String(count)),
	}
})
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

	// autoupdate-default-and-config-file: the default and how many apps follow it.
	// @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md
	it('says which default level the apps without a policy follow, and how many there are', () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: { policies, labels: {}, autoUpdateEnabled: true, window: '01:00-05:00', timeZone: 'UTC', defaultLevel: 'patch', followingDefault: 12 },
		})

		expect(wrapper.find('[data-testid="overview-default"]').text()).toBe('12 apps without their own policy follow the default level: Patch.')
		expect(wrapper.find('[data-testid="overview-managed-by"]').exists()).toBe(false)
	})

	it('names the policy file the policies are read from, and its error', () => {
		const wrapper = mount(AutoUpdateOverview, {
			props: {
				policies: {},
				labels: {},
				autoUpdateEnabled: false,
				window: '01:00-05:00',
				timeZone: 'UTC',
				followingDefault: 1,
				managedBy: { file: '/etc/nextcloud/policies.json', readAt: '2026-11-14T10:00:00+00:00', error: '$.policies.deck: must be one of none, patch, minor, all' },
			},
		})

		expect(wrapper.find('[data-testid="overview-managed-by"]').text()).toContain('/etc/nextcloud/policies.json')
		expect(wrapper.find('[data-testid="overview-file-error"]').text()).toContain('$.policies.deck: must be one of')
		expect(wrapper.find('[data-testid="overview-default"]').text()).toBe('1 app without its own policy follows the default level: Off.')
	})
})
