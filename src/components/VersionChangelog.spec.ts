import { mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Per-version changelog display" ("Expand a version row", "Markdown
// is not an XSS vector"):
// @spec openspec/specs/changelog-visibility/spec.md
import { describe, expect, it, vi } from 'vitest'
import VersionChangelog from './VersionChangelog.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

describe('VersionChangelog', () => {
	it('is collapsed by default', () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: 'Fixes LDAP sync' } })

		expect(wrapper.find('[data-testid="changelog-body"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="changelog-toggle"]').attributes('aria-expanded')).toBe('false')
	})

	it('expands to show the release notes on toggle click', async () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: 'Fixes LDAP sync' } })

		await wrapper.get('[data-testid="changelog-toggle"]').trigger('click')

		expect(wrapper.find('[data-testid="changelog-body"]').exists()).toBe(true)
		expect(wrapper.get('[data-testid="changelog-text"]').text()).toBe('Fixes LDAP sync')
		expect(wrapper.get('[data-testid="changelog-toggle"]').attributes('aria-expanded')).toBe('true')
	})

	it('collapses again on a second toggle click', async () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: 'Fixes LDAP sync' } })
		const toggle = wrapper.get('[data-testid="changelog-toggle"]')

		await toggle.trigger('click')
		await toggle.trigger('click')

		expect(wrapper.find('[data-testid="changelog-body"]').exists()).toBe(false)
	})

	it('shows the no-notes placeholder when changelog is null', async () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: null } })

		await wrapper.get('[data-testid="changelog-toggle"]').trigger('click')

		expect(wrapper.find('[data-testid="changelog-text"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="changelog-placeholder"]').text()).toBe('No release notes provided')
	})

	it('shows the no-notes placeholder when changelog is a blank string', async () => {
		const wrapper = mount(VersionChangelog, { props: { version: '2.3.0', changelog: '   ' } })

		await wrapper.get('[data-testid="changelog-toggle"]').trigger('click')

		expect(wrapper.get('[data-testid="changelog-placeholder"]').exists()).toBe(true)
	})

	it('renders a script-tag body as inert text, never as an executable element', async () => {
		const wrapper = mount(VersionChangelog, {
			props: { version: '2.3.0', changelog: '<script>alert(1)</script>' },
		})

		await wrapper.get('[data-testid="changelog-toggle"]').trigger('click')

		const textNode = wrapper.get('[data-testid="changelog-text"]')
		// Mustache interpolation only — the tag never becomes a DOM element.
		expect(wrapper.find('script').exists()).toBe(false)
		expect(textNode.text()).toBe('<script>alert(1)</script>')
		expect(textNode.html()).toContain('&lt;script&gt;')
	})
})
