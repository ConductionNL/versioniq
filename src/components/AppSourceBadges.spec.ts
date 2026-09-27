// SPDX-License-Identifier: EUPL-1.2
// Covers "Shipped apps and bound source on the app card" (#438 item 5):
// @spec openspec/specs/version-management/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AppSourceBadges from './AppSourceBadges.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

describe('AppSourceBadges', () => {
	it('marks a shipped app that is not always enabled', () => {
		const wrapper = mount(AppSourceBadges, { props: { isShipped: true, isCore: false, boundSourceId: null } })

		expect(wrapper.find('[data-testid="app-shipped-badge"]').exists()).toBe(true)
	})

	it('does not repeat the shipped flag on a core app, which already says CORE', () => {
		const wrapper = mount(AppSourceBadges, { props: { isShipped: true, isCore: true, boundSourceId: null } })

		expect(wrapper.find('[data-testid="app-shipped-badge"]').exists()).toBe(false)
	})

	it('names the bound source, and the App Store when there is none', () => {
		const bound = mount(AppSourceBadges, { props: { isShipped: false, isCore: false, boundSourceId: 'github:acme/app' } })
		expect(bound.find('[data-testid="app-source"]').text()).toContain('github:acme/app')

		const store = mount(AppSourceBadges, { props: { isShipped: false, isCore: false, boundSourceId: null } })
		expect(store.find('[data-testid="app-source"]').text()).toContain('App Store')
	})
})
