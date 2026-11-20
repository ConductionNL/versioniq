// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
// @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-a-withdrawn-installed-version-is-marked-and-never-reinstalled-by-accident
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SafetyBadges from './SafetyBadges.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

const mountWith = (props: { certificateRevoked?: boolean, installedWithdrawn?: boolean | null }) => mount(SafetyBadges, { props })

describe('SafetyBadges', () => {
	it('shows "Certificate revoked" with what a revoked certificate means', () => {
		const badge = mountWith({ certificateRevoked: true }).find('[data-testid="certificate-revoked-badge"]')

		expect(badge.text()).toBe('Certificate revoked')
		expect(badge.attributes('title')).toBe('Nextcloud revoked the signing certificate of this app. Nextcloud revokes a certificate to withdraw an app it knows to be compromised or malicious.')
	})

	it('shows "Version withdrawn by the publisher" when the installed version was withdrawn', () => {
		const wrapper = mountWith({ installedWithdrawn: true })

		expect(wrapper.find('[data-testid="version-withdrawn-badge"]').text()).toBe('Version withdrawn by the publisher')
		expect(wrapper.find('[data-testid="certificate-revoked-badge"]').exists()).toBe(false)
	})

	it('shows both when both apply', () => {
		const wrapper = mountWith({ certificateRevoked: true, installedWithdrawn: true })

		expect(wrapper.findAll('span').map((badge) => badge.attributes('data-testid'))).toEqual(['certificate-revoked-badge', 'version-withdrawn-badge'])
	})

	it('shows nothing for a trusted app, or when the withdrawn state is unknown', () => {
		expect(mountWith({}).html()).not.toContain('<p')
		expect(mountWith({ certificateRevoked: false, installedWithdrawn: false }).html()).not.toContain('<p')
		expect(mountWith({ installedWithdrawn: null }).html()).not.toContain('<p')
	})
})
