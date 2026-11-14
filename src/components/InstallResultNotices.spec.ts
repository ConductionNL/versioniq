// SPDX-License-Identifier: EUPL-1.2
// Covers "Unverified install warning" and "Served from cache" (#438 items 4 and 5):
// @spec openspec/specs/external-sources/spec.md
// And the update checks of a dry run (safety-update-checks 4.1):
// @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
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

	it('lists a passed, a failed and a skipped check with a word, not only a colour', () => {
		const wrapper = mount(InstallResultNotices, {
			props: {
				integrityWarning: null,
				servedFromCache: false,
				checks: [
					{ name: 'Lint', kind: 'command', outcome: 'passed', passed: true, detail: 'Exit code 0.' },
					{ name: 'Staging tests', kind: 'verdict', outcome: 'failed', passed: false, detail: 'HTTP 404' },
					{ name: 'Full suite', kind: 'command', outcome: 'skipped', passed: false, detail: 'ignored' },
				],
			},
		})

		const items = wrapper.findAll('[data-testid="install-check"]')
		expect(items).toHaveLength(3)
		expect(items[0].attributes('data-outcome')).toBe('passed')
		expect(items[0].text()).toContain('Lint')
		expect(items[0].text()).toContain('Passed')
		expect(items[0].text()).toContain('Exit code 0.')
		expect(items[1].attributes('data-outcome')).toBe('failed')
		expect(items[1].text()).toContain('Staging tests')
		expect(items[1].text()).toContain('Failed')
		expect(items[1].text()).toContain('HTTP 404')
		expect(items[2].attributes('data-outcome')).toBe('skipped')
		expect(items[2].text()).toContain('Skipped')
		expect(items[2].text()).toContain('Runs from the command line and the nightly job only.')
		expect(items[2].text()).not.toContain('ignored')
	})

	it('shows nothing when there are no checks and no notices', () => {
		const wrapper = mount(InstallResultNotices, { props: { integrityWarning: null, servedFromCache: false, checks: [] } })

		expect(wrapper.find('[data-testid="install-checks"]').exists()).toBe(false)
		expect(wrapper.html()).not.toContain('Update checks')
	})
})
