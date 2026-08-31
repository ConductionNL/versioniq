import { mount } from '@vue/test-utils'
// SPDX-License-Identifier: EUPL-1.2
// Covers "Aggregate range changelog on target selection" (rendering side —
// ordering itself is covered by utils/changelog.spec.ts):
// @spec openspec/specs/changelog-visibility/spec.md
import { describe, expect, it, vi } from 'vitest'
import ChangelogRangePanel from './ChangelogRangePanel.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
}))

describe('ChangelogRangePanel', () => {
	it('renders nothing when the range is empty', () => {
		const wrapper = mount(ChangelogRangePanel, { props: { entries: [] } })

		expect(wrapper.find('[data-testid="changelog-range-panel"]').exists()).toBe(false)
	})

	it('lists each entry with its version label and notes', () => {
		const wrapper = mount(ChangelogRangePanel, {
			props: {
				entries: [
					{ version: '2.4.0', changelog: 'Notes 2.4.0' },
					{ version: '2.5.0', changelog: 'Notes 2.5.0' },
				],
			},
		})

		const rows = wrapper.findAll('[data-testid="changelog-range-entry"]')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('2.4.0')
		expect(rows[0].text()).toContain('Notes 2.4.0')
		expect(rows[1].text()).toContain('2.5.0')
		expect(rows[1].text()).toContain('Notes 2.5.0')
	})

	it('shows the no-notes placeholder for entries without a changelog', () => {
		const wrapper = mount(ChangelogRangePanel, {
			props: { entries: [{ version: '2.4.0', changelog: null }] },
		})

		expect(wrapper.get('[data-testid="changelog-range-placeholder"]').text()).toBe('No release notes provided')
	})
})
