// SPDX-License-Identifier: EUPL-1.2
// Covers the "Certificate revoked" and "Version withdrawn by the publisher" card badges:
// @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SafetyBadges from './SafetyBadges.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

describe('SafetyBadges', () => {
	it('shows "Certificate revoked" with the wording of design D4', () => {
		const wrapper = mount(SafetyBadges, { props: { certificateRevoked: true, installedWithdrawn: null } })

		const badge = wrapper.find('[data-testid="certificate-revoked-badge"]')
		expect(badge.text()).toBe('Certificate revoked')
		expect(badge.attributes('title')).toBe('Nextcloud revoked the signing certificate of this app. Nextcloud revokes a certificate to withdraw an app it knows to be compromised or malicious.')
		expect(wrapper.find('[data-testid="version-withdrawn-badge"]').exists()).toBe(false)
	})

	it('shows "Version withdrawn by the publisher" only when the sweep said so', () => {
		const withdrawn = mount(SafetyBadges, { props: { certificateRevoked: false, installedWithdrawn: true } })
		expect(withdrawn.find('[data-testid="version-withdrawn-badge"]').text()).toBe('Version withdrawn by the publisher')
		expect(withdrawn.find('[data-testid="certificate-revoked-badge"]').exists()).toBe(false)

		// A failed listing reports null: it withdraws nothing.
		for (const installedWithdrawn of [false, null]) {
			const wrapper = mount(SafetyBadges, { props: { certificateRevoked: false, installedWithdrawn } })
			expect(wrapper.find('[data-testid="version-withdrawn-badge"]').exists()).toBe(false)
		}
	})

	it('shows both when both are true', () => {
		const wrapper = mount(SafetyBadges, { props: { certificateRevoked: true, installedWithdrawn: true } })

		expect(wrapper.findAll('span').map((span) => span.attributes('data-testid'))).toEqual(['certificate-revoked-badge', 'version-withdrawn-badge'])
	})
})
