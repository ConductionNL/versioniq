// SPDX-License-Identifier: EUPL-1.2
// Covers "Advisories view" (#438 items 2 and 3):
// @spec openspec/specs/security-advisory-correlation/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AdvisoriesPanel from './AdvisoriesPanel.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? count)),
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))

const serverRow = {
	appId: ':server',
	installedVersion: '31.0.2',
	state: 'pinned-to-vulnerable',
	advisories: [{ id: 'GHSA-srv1', severity: 'critical', summary: 'Server hole' }],
	recommendedVersion: '31.0.5',
	error: null,
}

describe('AdvisoriesPanel', () => {
	it('shows advisories about the Nextcloud server itself, with their severity', () => {
		const wrapper = mount(AdvisoriesPanel, {
			props: { advisories: { ':server': serverRow }, freshnessLabel: 'Checked 1 hour ago.' },
		})

		const server = wrapper.find('[data-testid="advisories-server"]')
		expect(server.exists()).toBe(true)
		expect(server.text()).toContain('31.0.2')
		expect(server.text()).toContain('GHSA-srv1')
		expect(server.text()).toContain('31.0.5')
		expect(server.find('[data-testid="advisory-severity"]').text()).toBe('Critical')
		expect(server.find('[data-testid="advisory-severity"]').attributes('data-severity')).toBe('critical')
	})

	it('says so when no published advisory is about the server', () => {
		const wrapper = mount(AdvisoriesPanel, { props: { advisories: {}, freshnessLabel: '' } })

		expect(wrapper.find('[data-testid="advisories-server"]').text()).toContain('No published advisory is about this server version.')
	})

	it('lists app advisories with a severity per advisory', () => {
		const wrapper = mount(AdvisoriesPanel, {
			props: {
				advisories: {
					calendar: {
						appId: 'calendar',
						installedVersion: '5.0.0',
						state: 'advisory-available',
						advisories: [
							{ id: 'GHSA-low', severity: 'low', summary: 'Minor' },
							{ id: 'GHSA-high', severity: 'high', summary: 'Major' },
						],
						recommendedVersion: null,
						error: null,
					},
				},
				freshnessLabel: '',
			},
		})

		const rows = wrapper.findAll('[data-testid="advisories-app-row"]')
		expect(rows).toHaveLength(1)
		const levels = rows[0].findAll('[data-testid="advisory-severity"]').map((s) => s.attributes('data-severity'))
		expect(levels).toEqual(['low', 'high'])
	})
})

describe('AdvisoriesPanel with NCSC-NL records (advisories-ncsc-feed)', () => {
	// @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	const ncsc = {
		id: 'NCSC-2026-0901',
		severity: 'high',
		summary: 'Kwetsbaarheden verholpen in Nextcloud Server',
		source: 'ncsc',
		cveIds: ['CVE-2026-40001', 'CVE-2026-40002'],
		ratings: { likelihood: 'medium', damage: 'high' },
		published: '2026-09-24T09:14:33Z',
		url: 'https://advisories.ncsc.nl/csaf/v2/2026/ncsc-2026-0901.json',
		match: 'affected' as const,
	}

	it('shows an NCSC-NL record with its label, CVE ids, both ratings, date and link', () => {
		const wrapper = mount(AdvisoriesPanel, {
			props: { advisories: { ':server': { ...serverRow, advisories: [ncsc] } }, freshnessLabel: '' },
		})

		const item = wrapper.find('[data-source="ncsc"]')
		expect(item.find('[data-testid="advisory-source"]').text()).toBe('NCSC-NL')
		expect(item.text()).toContain('NCSC-2026-0901')
		expect(item.find('[data-testid="advisory-cves"]').text()).toBe('CVE-2026-40001, CVE-2026-40002')
		expect(item.find('[data-testid="advisory-ratings"]').text()).toBe('NCSC-NL likelihood: medium, damage: high')
		expect(item.text()).toContain('Published 2026-09-24')
		expect(item.find('a').attributes('href')).toBe('https://advisories.ncsc.nl/csaf/v2/2026/ncsc-2026-0901.json')
		expect(item.find('[data-testid="advisory-not-stated"]').exists()).toBe(false)
	})

	it('says when NCSC-NL does not state which versions are affected', () => {
		const wrapper = mount(AdvisoriesPanel, {
			props: { advisories: { ':server': { ...serverRow, state: 'advisory-available', advisories: [{ ...ncsc, match: 'version_not_stated' as const }] } }, freshnessLabel: '' },
		})

		expect(wrapper.find('[data-testid="advisory-not-stated"]').text()).toBe('NCSC-NL does not state which versions are affected.')
		expect(wrapper.text()).not.toContain('This server version is affected by a published advisory.')
	})

	it('shows a merged record with both ids', () => {
		const merged = { id: 'GHSA-srv1', severity: 'critical', summary: 'Server hole', relatedIds: ['NCSC-2026-0901'] }
		const wrapper = mount(AdvisoriesPanel, {
			props: { advisories: { ':server': { ...serverRow, advisories: [merged] } }, freshnessLabel: '' },
		})

		const server = wrapper.find('[data-testid="advisories-server"]')
		expect(server.text()).toContain('GHSA-srv1')
		expect(server.find('[data-testid="advisory-related"]').text()).toBe('Also published as NCSC-2026-0901')
		expect(server.findAll('[data-testid="advisory-severity"]')).toHaveLength(1)
	})

	it('says NCSC-NL is catching up until a sweep reaches the present', () => {
		const catchingUp = mount(AdvisoriesPanel, {
			props: { advisories: {}, freshnessLabel: '', ncscStatus: { enabled: true, readUpTo: '2026-03-02T08:00:00+00:00', caughtUp: false } },
		})
		expect(catchingUp.find('[data-testid="advisories-ncsc-catching-up"]').text()).toBe('NCSC-NL: catching up, read up to 2026-03-02')

		const caughtUp = mount(AdvisoriesPanel, {
			props: { advisories: {}, freshnessLabel: '', ncscStatus: { enabled: true, readUpTo: '2026-09-27T08:00:00+00:00', caughtUp: true } },
		})
		expect(caughtUp.find('[data-testid="advisories-ncsc-catching-up"]').exists()).toBe(false)

		const off = mount(AdvisoriesPanel, {
			props: { advisories: {}, freshnessLabel: '', ncscStatus: { enabled: false, readUpTo: null, caughtUp: false } },
		})
		expect(off.find('[data-testid="advisories-ncsc-catching-up"]').exists()).toBe(false)
	})
})
