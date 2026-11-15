// SPDX-License-Identifier: EUPL-1.2
// Covers "Unverified install warning" and "Served from cache" (#438 items 4 and 5):
// @spec openspec/specs/external-sources/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import InstallResultNotices from './InstallResultNotices.vue'

vi.mock('@nextcloud/l10n', () => ({
	getCanonicalLocale: () => 'en-GB',
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

	// @spec openspec/specs/version-management/spec.md
	it('shows the free space the pre-flight found and that the app folder is writable', () => {
		const wrapper = mount(InstallResultNotices, { props: { integrityWarning: null, servedFromCache: false, space: { appsFree: 9663676416, tempFree: 512 } } })

		expect(wrapper.find('[data-testid="install-environment-checked"]').text())
			.toBe('Environment checked: 9.0 GB free in the apps folder, 512 B free in the temp folder; the app folder is writable.')
	})

	it('says when a folder\'s free space is unknown, and shows nothing without a measurement', () => {
		const unknown = mount(InstallResultNotices, { props: { integrityWarning: null, servedFromCache: false, space: { appsFree: null, tempFree: 1048576 } } })
		expect(unknown.find('[data-testid="install-environment-checked"]').text()).toContain('unknown free in the apps folder, 1.0 MB free in the temp folder')

		const none = mount(InstallResultNotices, { props: { integrityWarning: null, servedFromCache: false } })
		expect(none.find('[data-testid="install-environment-checked"]').exists()).toBe(false)
	})

	// @spec openspec/specs/migration-safety/spec.md
	it('names the backup with its size and until when it is kept', () => {
		const wrapper = mount(InstallResultNotices, { props: { integrityWarning: null, servedFromCache: false, backup: { made: true, sizeBytes: 190840832, keptUntil: '2026-10-15' } } })

		expect(wrapper.find('[data-testid="install-backup"]').text())
			.toBe('Backup made: database and app folder, 182.0 MB, kept until 15 Oct.')
	})

	it('says when backups are off and when the backup failed', () => {
		const off = mount(InstallResultNotices, { props: { integrityWarning: null, servedFromCache: false, backup: { made: false, reason: 'disabled' } } })
		expect(off.find('[data-testid="install-backup"]').text()).toBe('No backup was made: backups are turned off.')

		const failed = mount(InstallResultNotices, { props: { integrityWarning: null, servedFromCache: false, backup: { made: false, reason: 'failed', message: 'disk full' } } })
		expect(failed.find('[data-testid="install-backup"]').text()).toBe('The backup could not be made: disk full')
	})
})
