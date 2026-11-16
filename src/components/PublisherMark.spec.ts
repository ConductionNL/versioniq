// SPDX-License-Identifier: EUPL-1.2
// Covers "Hits and cards name the publisher and the trust marks the data backs":
// @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import PublisherMark from './PublisherMark.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

describe('PublisherMark', () => {
	it('names the publisher', () => {
		const wrapper = mount(PublisherMark, { props: { publisher: 'Nextcloud GmbH' } })

		expect(wrapper.get('[data-testid="publisher-name"]').text()).toBe('By Nextcloud GmbH')
		expect(wrapper.find('[data-testid="publisher-trust-mark"]').exists()).toBe(false)
	})

	it('says the publisher is unknown rather than leaving it blank', () => {
		const wrapper = mount(PublisherMark, { props: { publisher: '  ' } })

		expect(wrapper.get('[data-testid="publisher-name"]').text()).toBe('Publisher unknown')
	})

	it('shows each backed mark in a fixed order, with a tooltip naming who made the claim', () => {
		const wrapper = mount(PublisherMark, {
			props: { publisher: 'Nextcloud GmbH', marks: ['trusted', 'featured', 'verifiedOrg', 'shipped'] },
		})

		const marks = wrapper.findAll('[data-testid="publisher-trust-mark"]')
		expect(marks.map((mark) => mark.attributes('data-mark'))).toEqual(['shipped', 'featured', 'verifiedOrg', 'trusted'])
		expect(marks[0].attributes('title')).toBe('The Nextcloud server ships this app.')
		expect(marks[1].text()).toContain('Featured in the App Store')
		expect(marks[1].attributes('title')).toBe('The App Store marks this app as featured.')
		expect(marks[2].text()).toContain('Verified organisation')
		expect(marks[2].attributes('title')).toBe('GitHub has verified the domain of this organisation.')
		expect(marks[3].text()).toContain('On your trusted list')
		expect(marks[3].attributes('title')).toBe('An administrator put this source on the trusted list.')
	})

	it('drops a mark no source backs', () => {
		const wrapper = mount(PublisherMark, { props: { publisher: 'someone', marks: ['safe', 'featured'] } })

		const marks = wrapper.findAll('[data-testid="publisher-trust-mark"]')
		expect(marks).toHaveLength(1)
		expect(marks[0].attributes('data-mark')).toBe('featured')
	})

	it('names the other publisher in a similar-name hint', () => {
		const wrapper = mount(PublisherMark, {
			props: {
				publisher: 'Nextcloud GmbH',
				similarTo: [
					{ appId: 'white-board', name: 'White Board', publisher: 'Someone Else' },
					{ appId: 'whiteboardapp', name: 'WhiteboardApp', publisher: null },
				],
			},
		})

		const hints = wrapper.findAll('[data-testid="publisher-similar-name"]')
		expect(hints.map((hint) => hint.text())).toEqual([
			'Similar name: White Board by Someone Else',
			'Similar name: WhiteboardApp by an unknown publisher',
		])
	})
})
