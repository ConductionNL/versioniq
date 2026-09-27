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
	})
})
