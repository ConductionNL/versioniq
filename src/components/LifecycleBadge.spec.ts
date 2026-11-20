// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import LifecycleBadge from './LifecycleBadge.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

const badgeOf = (lifecycle: Record<string, boolean | null> | null) => mount(LifecycleBadge, { props: { lifecycle } }).find('[data-testid="lifecycle-badge"]')

describe('LifecycleBadge', () => {
	it('says an app left the App Store', () => {
		const badge = badgeOf({ removedFromStore: true, archived: null, noReleaseFor12Months: false })

		expect(badge.text()).toBe('Removed from the App Store')
		expect(badge.attributes('data-signal')).toBe('removedFromStore')
		expect(badge.attributes('title')).toBe('')
	})

	it('says the repository is archived', () => {
		const badge = badgeOf({ removedFromStore: false, archived: true })

		expect(badge.text()).toBe('Repository archived')
		expect(badge.attributes('data-signal')).toBe('archived')
	})

	it('says an app is out of support', () => {
		const badge = badgeOf({ removedFromStore: false, archived: false, endOfSupport: true })

		expect(badge.text()).toBe('End of support')
		expect(badge.attributes('data-signal')).toBe('endOfSupport')
	})

	it('says an app had no release in a year', () => {
		const badge = badgeOf({ removedFromStore: false, noReleaseFor12Months: true })

		expect(badge.text()).toBe('No release in a year')
		expect(badge.attributes('data-signal')).toBe('noReleaseFor12Months')
	})

	it('shows the strongest signal and the others in its tooltip', () => {
		const badge = badgeOf({ removedFromStore: false, archived: true, endOfSupport: true, noReleaseFor12Months: true })

		expect(badge.text()).toBe('Repository archived')
		expect(badge.attributes('title')).toBe('End of support, No release in a year')
	})

	it('shows nothing for an app without signals or with unknown ones', () => {
		expect(badgeOf({ removedFromStore: false, archived: null, endOfSupport: null, noReleaseFor12Months: false }).exists()).toBe(false)
		expect(badgeOf(null).exists()).toBe(false)
	})
})
