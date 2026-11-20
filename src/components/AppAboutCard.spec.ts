// SPDX-License-Identifier: EUPL-1.2
// Covers the About card of an app's version view (inventory-app-details-card):
// @spec openspec/changes/inventory-app-details-card/tasks.md#2-page
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AppAboutCard from './AppAboutCard.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

const DOSSIQ = {
	id: 'dossiq',
	label: 'Dossiq',
	description: 'Dossiers en zaken.',
	author: 'Conduction',
	licence: 'agpl',
	links: {
		website: 'https://dossiq.example.nl',
		repository: 'https://github.com/ConductionNL/dossiq',
		bugs: 'https://github.com/ConductionNL/dossiq/issues',
	},
	installedVersion: '1.4.0',
	state: 'enabled' as const,
}

describe('AppAboutCard', () => {
	it('shows publisher, licence, installed version and description', () => {
		const wrapper = mount(AppAboutCard, { props: { app: DOSSIQ } })

		expect(wrapper.find('h3').text()).toBe('About Dossiq')
		expect(wrapper.find('[data-testid="app-about-publisher"]').text()).toBe('Conduction')
		expect(wrapper.find('[data-testid="app-about-licence"]').text()).toBe('agpl')
		expect(wrapper.find('[data-testid="app-about-installed"]').text()).toBe('1.4.0 (enabled)')
		expect(wrapper.find('[data-testid="app-about-description"]').text()).toBe('Dossiers en zaken.')
	})

	it('renders all three links in a new tab without an opener', () => {
		const wrapper = mount(AppAboutCard, { props: { app: DOSSIQ } })

		const links = wrapper.findAll('a')
		expect(links.map((link) => [link.text(), link.attributes('href')])).toEqual([
			['Website', 'https://dossiq.example.nl'],
			['Source', 'https://github.com/ConductionNL/dossiq'],
			['Report a problem', 'https://github.com/ConductionNL/dossiq/issues'],
		])
		for (const link of links) {
			expect(link.attributes('target')).toBe('_blank')
			expect(link.attributes('rel')).toBe('noopener noreferrer')
		}
	})

	it('leaves out a link the app does not declare', () => {
		const wrapper = mount(AppAboutCard, { props: { app: { ...DOSSIQ, links: { website: null, repository: 'https://github.com/ConductionNL/dossiq', bugs: null } } } })

		expect(wrapper.findAll('a').map((link) => link.text())).toEqual(['Source'])
		expect(wrapper.find('[data-testid="app-about-link-bugs"]').exists()).toBe(false)
	})

	it('shows the disabled state, and not installed without a version', () => {
		const disabled = mount(AppAboutCard, { props: { app: { ...DOSSIQ, state: 'disabled' as const } } })
		expect(disabled.find('[data-testid="app-about-installed"]').text()).toBe('1.4.0 (disabled)')

		const absent = mount(AppAboutCard, { props: { app: { id: 'hermiq', label: 'hermiq', installedVersion: null, state: 'notInstalled' as const } } })
		expect(absent.find('[data-testid="app-about-installed"]').text()).toBe('Not installed')
		expect(absent.find('[data-testid="app-about-publisher"]').text()).toBe('—')
		expect(absent.findAll('a')).toHaveLength(0)
		expect(absent.find('[data-testid="app-about-description"]').exists()).toBe(false)
	})
})
