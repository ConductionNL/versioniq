// SPDX-License-Identifier: EUPL-1.2
// Covers "Server compatibility in the web version picker" (#435):
// @spec openspec/specs/version-management/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ServerCompatBadge from './ServerCompatBadge.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

describe('ServerCompatBadge', () => {
	it('says a compatible version runs on this server', () => {
		const wrapper = mount(ServerCompatBadge, { props: { serverCompatible: true } })

		expect(wrapper.find('[data-testid="server-compat-badge"]').attributes('data-compatible')).toBe('yes')
		expect(wrapper.text()).toContain('Runs on this server')
	})

	it('warns when a version does not support this server', () => {
		const wrapper = mount(ServerCompatBadge, { props: { serverCompatible: false } })

		expect(wrapper.find('[data-testid="server-compat-badge"]').attributes('data-compatible')).toBe('no')
		expect(wrapper.text()).toContain('Not for this server version')
	})

	it('renders nothing when compatibility is unknown', () => {
		const wrapper = mount(ServerCompatBadge, { props: { serverCompatible: null } })

		expect(wrapper.find('[data-testid="server-compat-badge"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="php-compat-badge"]').exists()).toBe(false)
	})

	// inventory-upgrade-readiness 1.3:
	// @spec openspec/changes/inventory-upgrade-readiness/specs/version-management/spec.md#requirement-versions-say-whether-they-run-on-this-servers-php
	it('shows a version that does not run on this PHP as such', () => {
		const wrapper = mount(ServerCompatBadge, { props: { serverCompatible: true, phpCompatible: false } })
		const php = wrapper.find('[data-testid="php-compat-badge"]')

		expect(php.attributes('data-compatible')).toBe('no')
		expect(php.text()).toBe('Not for this PHP version')
		expect(php.attributes('title')).toContain('installing it will likely fail')
		expect(wrapper.find('[data-testid="server-compat-badge"]').attributes('data-compatible')).toBe('yes')
	})

	it('says a version runs on this PHP, even when the server range is unknown', () => {
		const wrapper = mount(ServerCompatBadge, { props: { serverCompatible: null, phpCompatible: true } })

		expect(wrapper.find('[data-testid="php-compat-badge"]').text()).toBe('Runs on this PHP version')
		expect(wrapper.find('[data-testid="server-compat-badge"]').exists()).toBe(false)
	})

	it('renders the PHP line only when phpCompatible is not null', () => {
		for (const props of [{ serverCompatible: true }, { serverCompatible: false, phpCompatible: null }]) {
			const wrapper = mount(ServerCompatBadge, { props })

			expect(wrapper.find('[data-testid="php-compat-badge"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="server-compat-badge"]').exists()).toBe(true)
		}
	})
})
