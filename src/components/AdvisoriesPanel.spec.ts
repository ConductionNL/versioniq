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
		(count === 1 ? one : many).replace('%n', String(count)).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? count)),
}))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))

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

// advisories-per-app-detail 4.1: the history per app, newest first, five at a time.
// @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
describe('AdvisoriesPanel history', () => {
	const entry = (id: string, publishedAt: string | null, affectsInstalled: boolean, cveIds: string[] = []) => ({
		id, severity: 'medium', summary: 'Summary ' + id, cveIds, publishedAt, url: null, affectsInstalled,
	})
	const fixtureapp = {
		appId: 'fixtureapp',
		installedVersion: '1.0.0',
		state: 'pinned-to-vulnerable',
		advisories: [{ id: 'GHSA-new', severity: 'medium', summary: '' }],
		recommendedVersion: null,
		error: null,
		fixStatus: 'no_fix_published',
		history: [
			entry('GHSA-new', '2026-08-01T00:00:00Z', true, ['CVE-2026-1111']),
			entry('GHSA-old', '2025-11-02T00:00:00Z', false),
			entry('GHSA-3', '2025-01-01T00:00:00Z', false),
			entry('GHSA-4', '2024-01-01T00:00:00Z', false),
			entry('GHSA-5', '2023-01-01T00:00:00Z', false),
			entry('GHSA-6', null, false),
		],
	}

	function mountWith (row: Record<string, unknown>, compliance: unknown = null) {
		return mount(AdvisoriesPanel, { props: { advisories: { fixtureapp: row } as never, freshnessLabel: '', compliance: compliance as never } })
	}

	it('lists the newest first with its CVE id, date and whether it affects the installed version', () => {
		const block = mountWith(fixtureapp).find('#section-advisories-fixtureapp')
		expect(block.exists()).toBe(true)
		const entries = block.findAll('[data-testid="advisory-entry"]')
		expect(entries[0].text()).toContain('CVE-2026-1111')
		expect(entries[0].find('[data-testid="advisory-date"]').text()).toBe('2026-08-01')
		expect(entries[0].find('[data-testid="advisory-affects"]').text()).toBe('Affects the installed version')
		expect(entries[1].text()).toContain('GHSA-old')
		expect(entries[1].find('[data-testid="advisory-cve"]').text()).toBe('No CVE id')
		expect(entries[1].find('[data-testid="advisory-affects"]').text()).toBe('Fixed in the installed version')
	})

	it('shows the five most recent and the rest behind Show all', async () => {
		const wrapper = mountWith(fixtureapp)
		expect(wrapper.findAll('[data-testid="advisory-entry"]')).toHaveLength(5)
		const showAll = wrapper.find('[data-testid="advisories-show-all"]')
		expect(showAll.text()).toBe('Show all 6')

		await showAll.trigger('click')

		const entries = wrapper.findAll('[data-testid="advisory-entry"]')
		expect(entries).toHaveLength(6)
		expect(entries[5].find('[data-testid="advisory-date"]').text()).toBe('Date unknown')
		expect(wrapper.find('[data-testid="advisories-show-all"]').exists()).toBe(false)
	})

	it.each([
		['no_fix_published', {}, 'No fixed version is published yet.'],
		['installable', { recommendedVersion: '1.0.1' }, 'Install 1.0.1 to fix it.'],
		['not_in_source', { recommendedVersion: '1.0.1' }, 'The fixed version 1.0.1 is not offered by the source this app installs from.'],
		['needs_newer_server', { recommendedVersion: '1.0.1' }, 'The fix needs a newer Nextcloud than this server runs.'],
		['installable', { recommendedVersion: '1.0.1', heldByPin: true }, 'A pin holds this app below the fix 1.0.1.'],
	])('states the fix sentence for %s', (fixStatus, extra, sentence) => {
		const wrapper = mountWith({ ...fixtureapp, fixStatus, ...extra })
		expect(wrapper.find('[data-testid="advisory-fix"]').text()).toBe(sentence)
	})

	it('shows the compliance line at the top', () => {
		const wrapper = mountWith(fixtureapp, { status: 'not_compliant', reason: null, affected: 1, bySeverity: { medium: 1 }, affectedIds: ['fixtureapp'], unreached: 0, checkedAt: 1 })
		expect(wrapper.find('[data-testid="advisories-compliance"]').text()).toBe('Not compliant: 1 app runs an affected version (1 medium)')
	})
})
