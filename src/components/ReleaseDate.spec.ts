// SPDX-License-Identifier: EUPL-1.2
// Covers "each version MUST show: version number, release date" (#480).
// @spec openspec/specs/version-management/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ReleaseDate from './ReleaseDate.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	getCanonicalLocale: () => 'en-GB',
}))

describe('ReleaseDate', () => {
	it('shows the release date in the admin locale, with the full timestamp as machine value', () => {
		const wrapper = mount(ReleaseDate, { props: { releasedAt: '2026-03-05T10:15:30Z' } })

		const time = wrapper.find('[data-testid="release-date"]')
		expect(time.text()).toBe('Released 5 Mar 2026')
		expect(time.attributes('datetime')).toBe('2026-03-05T10:15:30Z')
	})

	it('renders nothing when the source gave no date', () => {
		const wrapper = mount(ReleaseDate, { props: { releasedAt: null } })

		expect(wrapper.find('[data-testid="release-date"]').exists()).toBe(false)
	})

	it('renders nothing for a value that is not a date', () => {
		const wrapper = mount(ReleaseDate, { props: { releasedAt: 'not a date' } })

		expect(wrapper.find('[data-testid="release-date"]').exists()).toBe(false)
	})
})
