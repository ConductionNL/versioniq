// SPDX-License-Identifier: EUPL-1.2
// Covers "Unverified install warning" and "Served from cache" (#438 items 4 and 5):
// @spec openspec/specs/external-sources/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import InstallResultNotices from './InstallResultNotices.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

describe('InstallResultNotices', () => {
	it('shows the unverified-install warning the installer returned', () => {
		const wrapper = mount(InstallResultNotices, { props: { integrityWarning: 'No checksum was published.', servedFromCache: false } })

		const warning = wrapper.find('[data-testid="install-integrity-warning"]')
		expect(warning.exists()).toBe(true)
		expect(warning.text()).toContain('without checksum verification')
		expect(warning.text()).toContain('No checksum was published.')
		expect(warning.attributes('role')).toBe('alert')
	})

	it('says when the artifact came from the local cache', () => {
		const wrapper = mount(InstallResultNotices, { props: { integrityWarning: null, servedFromCache: true } })

		expect(wrapper.find('[data-testid="install-integrity-warning"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="install-served-from-cache"]').exists()).toBe(true)
	})
})
