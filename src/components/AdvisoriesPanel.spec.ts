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
