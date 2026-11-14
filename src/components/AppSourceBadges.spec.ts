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

	// @spec openspec/changes/inventory-pins-checks-and-pending/specs/external-sources/spec.md#requirement-the-card-names-where-the-installed-version-came-from
	it('names the source of the installed version apart from the binding', () => {
		const wrapper = mount(AppSourceBadges, {
			props: { isShipped: false, isCore: false, boundSourceId: null, provenance: { kind: 'versioniq', sourceId: 'github:ConductionNL/pipelinq', version: '1.12.0' } },
		})

		expect(wrapper.find('[data-testid="app-source"]').text()).toContain('App Store')
		expect(wrapper.find('[data-testid="app-provenance"]').text()).toBe('1.12.0 installed from github:ConductionNL/pipelinq')
	})

	it('says a version was set outside Versioniq', () => {
		const wrapper = mount(AppSourceBadges, {
			props: { isShipped: false, isCore: false, boundSourceId: null, provenance: { kind: 'outside', sourceId: null, version: '1.15.0' } },
		})

		expect(wrapper.find('[data-testid="app-provenance"]').text()).toBe('Set to 1.15.0 outside Versioniq')
	})

	it('shows no provenance for an app that is not installed', () => {
		const wrapper = mount(AppSourceBadges, { props: { isShipped: false, isCore: false, boundSourceId: null } })

		expect(wrapper.find('[data-testid="app-provenance"]').exists()).toBe(false)
	})
})
