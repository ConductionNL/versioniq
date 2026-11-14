// SPDX-License-Identifier: EUPL-1.2
// The bundled-library section of one app on the Advisories tab, and the
// Bundled libraries block that holds it (advisories-bundled-libraries).
// @spec openspec/changes/advisories-bundled-libraries
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AdvisoriesPanel from './AdvisoriesPanel.vue'
import BundledLibrariesSection from './BundledLibrariesSection.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number) => (count === 1 ? one : many).replace('%n', String(count)),
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const finding = {
	package: 'vendor/library',
	version: '2.4.3',
	id: 'GHSA-xxxx-xxxx-xxxx',
	cveIds: ['CVE-0000-00000'],
	severity: 'medium',
	summary: 'A hole in the library',
	fixedIn: '2.4.5',
	url: 'https://osv.dev/vulnerability/GHSA-xxxx-xxxx-xxxx',
	malicious: false,
}

function check (overrides: Record<string, unknown> = {}) {
  return {
	manifest: 'vendor/composer/installed.json',
	checked: 41,
	skipped: 1,
	error: null,
	findings: [] as typeof finding[],
	...overrides,
}
}

describe('BundledLibrariesSection', () => {
	it('lists a finding with its severity, advisory, CVE, fixed version and next step', () => {
		const wrapper = mount(BundledLibrariesSection, { props: { appId: 'mail', check: check({ findings: [finding] }) } })

		expect(wrapper.attributes('id')).toBe('bundled-libraries-mail')
		expect(wrapper.find('[data-testid="bundled-libraries-checked"]').text()).toBe('41 PHP libraries checked')
		const row = wrapper.find('[data-testid="bundled-library-finding"]')
		expect(row.text()).toContain('vendor/library 2.4.3')
		expect(row.text()).toContain('CVE-0000-00000')
		expect(row.text()).toContain('A hole in the library')
		expect(row.find('a').attributes()).toMatchObject({ href: finding.url, rel: 'noopener noreferrer' })
		expect(row.find('a').text()).toBe('GHSA-xxxx-xxxx-xxxx')
		expect(row.find('[data-testid="advisory-severity"]').attributes('data-severity')).toBe('medium')
		expect(row.find('[data-testid="bundled-library-fixed"]').text()).toBe('Fixed in 2.4.5')
		expect(row.find('[data-testid="bundled-library-advice"]').text())
			.toBe('Look for a newer version of this app that bundles vendor/library 2.4.5 or later, or ask its maintainer.')
		expect(wrapper.find('[data-testid="bundled-libraries-clean"]').exists()).toBe(false)
	})

	it('labels a malicious package as such and advises removing the app', () => {
		const evil = { ...finding, package: 'vendor/evil', version: '1.0.0', id: 'MAL-2026-1', fixedIn: null, malicious: true, cveIds: [] }
		const wrapper = mount(BundledLibrariesSection, { props: { appId: 'mail', check: check({ findings: [evil] }) } })

		expect(wrapper.find('[data-testid="bundled-library-malicious"]').text()).toBe('Known malicious package')
		expect(wrapper.find('[data-testid="advisory-severity"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="bundled-library-fixed"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('MAL-2026-1')
		expect(wrapper.find('[data-testid="bundled-library-advice"]').text())
			.toBe('Remove this app or replace it with one that does not bundle vendor/evil.')
	})

	it('asks the maintainer when no fixed version is known', () => {
		const wrapper = mount(BundledLibrariesSection, { props: { appId: 'mail', check: check({ findings: [{ ...finding, fixedIn: null }] }) } })

		expect(wrapper.find('[data-testid="bundled-library-advice"]').text())
			.toBe('No fixed version of vendor/library is known yet; ask the app\'s maintainer.')
	})

	it('says a clean app has no known advisory', () => {
		const wrapper = mount(BundledLibrariesSection, { props: { appId: 'files', check: check({ checked: 1 }) } })

		expect(wrapper.find('[data-testid="bundled-libraries-checked"]').text()).toBe('1 PHP library checked')
		expect(wrapper.find('[data-testid="bundled-libraries-clean"]').text()).toBe('No known advisory for these libraries.')
		expect(wrapper.find('[data-testid="bundled-library-finding"]').exists()).toBe(false)
	})

	it('names an app without a manifest as not checked', () => {
		const wrapper = mount(BundledLibrariesSection, { props: { appId: 'calendar', check: check({ manifest: null, checked: 0 }) } })

		expect(wrapper.find('[data-testid="bundled-libraries-no-manifest"]').text()).toBe('No library manifest found, so its libraries were not checked.')
		expect(wrapper.find('[data-testid="bundled-libraries-checked"]').exists()).toBe(false)
	})

	it('shows why part of the libraries were not checked', () => {
		const wrapper = mount(BundledLibrariesSection, { props: { appId: 'mail', check: check({ error: 'Some libraries were not checked this run.' }) } })

		expect(wrapper.find('[data-testid="bundled-libraries-error"]').text()).toBe('Some libraries were not checked this run.')
	})
})

describe('AdvisoriesPanel bundled libraries', () => {
	it('lists the apps with findings first and says JavaScript is not checked', () => {
		const wrapper = mount(AdvisoriesPanel, {
			props: {
				advisories: {},
				freshnessLabel: '',
				libraries: { calendar: check({ manifest: null, checked: 0 }), mail: check({ findings: [finding] }), files: check() },
				librariesFreshnessLabel: 'Libraries checked 2 h ago',
			},
		})

		const block = wrapper.find('[data-testid="bundled-libraries"]')
		expect(block.attributes('id')).toBe('section-bundled-libraries')
		expect(block.text()).toContain('Libraries checked 2 h ago')
		expect(block.findAll('[data-testid="bundled-libraries-app"]').map((app) => app.attributes('id')))
			.toEqual(['bundled-libraries-mail', 'bundled-libraries-calendar', 'bundled-libraries-files'])
		expect(block.find('[data-testid="bundled-libraries-js"]').text()).toBe('JavaScript built into an app carries no library manifest and is not checked.')
		expect(block.find('[data-testid="bundled-libraries-never"]').exists()).toBe(false)
	})

	it('says the libraries were never checked when there is no snapshot', () => {
		const wrapper = mount(AdvisoriesPanel, { props: { advisories: {}, freshnessLabel: '' } })

		expect(wrapper.find('[data-testid="bundled-libraries-never"]').text())
			.toBe('The libraries bundled in apps have not been checked. Switch the check on under Security advisory checks.')
		expect(wrapper.find('[data-testid="bundled-libraries-js"]').exists()).toBe(true)
	})
})
