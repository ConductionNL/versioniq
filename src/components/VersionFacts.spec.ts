// SPDX-License-Identifier: EUPL-1.2
// Each fact of a version row shows when the entry carries it and is absent
// when it does not: downloads, outcomes here, "Major version", "Breaking",
// the publisher's known-issues section and the admin's notes (task 4.1).
// @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
// @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import VersionFacts from './VersionFacts.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	n: (_app: string, one: string, many: string, count: number, vars: Record<string, unknown> = {}) =>
		(count === 1 ? one : many).replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

const full = {
	version: '2.4.0',
	downloads: 312,
	outcomes: { succeeded: 2, failed: 1 },
	breaking: true,
	knownIssues: 'LDAP sync stops after 1000 users; set the page size to 500',
	adminKnownIssues: [
		{ text: 'Export to CSV times out on large registers', workaround: 'Export per schema', link: 'https://example.org/issue/1', addedBy: 'alice' },
		{ text: 'Second note' },
	],
}

describe('VersionFacts', () => {
	it('shows every fact the entry carries', () => {
		const wrapper = mount(VersionFacts, { props: { entry: full, installedVersion: '1.9.0' } })

		expect(wrapper.find('[data-testid="fact-major"]').text()).toBe('Major version')
		expect(wrapper.find('[data-testid="fact-breaking"]').text()).toBe('Breaking')
		expect(wrapper.find('[data-testid="fact-downloads"]').text()).toBe('312 downloads')
		expect(wrapper.find('[data-testid="fact-outcomes"]').text()).toBe('Installed 3 times here, 1 failure')
		expect(wrapper.find('[data-testid="fact-notes-issues"]').text()).toBe(full.knownIssues)
		const notes = wrapper.findAll('[data-testid="fact-admin-issue"]')
		expect(notes).toHaveLength(2)
		expect(notes[0].text()).toContain('Export to CSV times out on large registers')
		expect(notes[0].text()).toContain('Workaround: Export per schema')
		expect(notes[0].text()).toContain('Added by alice')
		expect(notes[0].find('a').attributes('href')).toBe('https://example.org/issue/1')
		expect(notes[0].find('a').attributes('rel')).toBe('noopener noreferrer')
		expect(notes[1].find('a').exists()).toBe(false)
	})

	it('shows none of them for a bare entry, and no zero for an unknown download count', () => {
		const wrapper = mount(VersionFacts, {
			props: { entry: { version: '2.4.0', downloads: null, outcomes: { succeeded: 0, failed: 0 }, breaking: false, knownIssues: '', adminKnownIssues: [] }, installedVersion: '2.3.0' },
		})

		for (const id of ['fact-major', 'fact-breaking', 'fact-downloads', 'fact-outcomes', 'fact-known-issues']) {
			expect(wrapper.find(`[data-testid="${id}"]`).exists(), id).toBe(false)
		}
		expect(wrapper.find('[data-testid="fact-add-issue"]').exists()).toBe(true)
	})

	it('names no major step without an installed version, and a single download in the singular', () => {
		const wrapper = mount(VersionFacts, { props: { entry: { version: '3.0.0', downloads: 1, outcomes: { succeeded: 1, failed: 0 } }, installedVersion: '' } })

		expect(wrapper.find('[data-testid="fact-major"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="fact-downloads"]').text()).toBe('1 download')
		expect(wrapper.find('[data-testid="fact-outcomes"]').text()).toBe('Installed 1 time here')
	})

	it('shows the totals across this instance and the connected ones once they are read', () => {
		const instanceReads = [
			{ id: 'test', label: 'Test', outcomes: { '2.4.0': { succeeded: 1, failed: 0 } }, error: null },
			{ id: 'legacy', label: 'Legacy', outcomes: null, error: 'The instance could not be reached.' },
		]
		const wrapper = mount(VersionFacts, { props: { entry: full, installedVersion: '2.4.0', instanceReads } })

		expect(wrapper.find('[data-testid="fact-outcomes"]').text()).toBe('Installed 3 times on 2 instances, 1 failure')
	})

	it('keeps the outcomes here while no connection was read', () => {
		const wrapper = mount(VersionFacts, { props: { entry: full, installedVersion: '2.4.0', instanceReads: [] } })

		expect(wrapper.find('[data-testid="fact-outcomes"]').text()).toBe('Installed 3 times here, 1 failure')
	})

	it('shows the publisher section alone when no admin note exists', () => {
		const wrapper = mount(VersionFacts, { props: { entry: { version: '1.4.0', knownIssues: '  Known: x  ' }, installedVersion: '1.4.0' } })

		expect(wrapper.find('[data-testid="fact-notes-issues"]').text()).toBe('Known: x')
		expect(wrapper.findAll('[data-testid="fact-admin-issue"]')).toHaveLength(0)
	})

	it('asks to add a note and to remove one by its position', async () => {
		const wrapper = mount(VersionFacts, { props: { entry: full, installedVersion: '2.4.0' } })

		await wrapper.find('[data-testid="fact-add-issue"]').trigger('click')
		await wrapper.findAll('[data-testid="fact-remove-issue"]')[1].trigger('click')

		expect(wrapper.emitted('add')).toEqual([['2.4.0']])
		expect(wrapper.emitted('remove')).toEqual([['2.4.0', 1]])
	})

	it('disables its buttons while busy', () => {
		const wrapper = mount(VersionFacts, { props: { entry: full, installedVersion: '2.4.0', busy: true } })

		expect(wrapper.find('[data-testid="fact-add-issue"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="fact-remove-issue"]').attributes('disabled')).toBeDefined()
	})
})
