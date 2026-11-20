// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Covers the panel of "A dry run of an upgrade previews the database changes":
// @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
import type { MigrationPreview, MigrationPreviewStep } from '../utils/migrationSafety.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import MigrationPreviewPanel from './MigrationPreviewPanel.vue'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))

function step (over: Partial<MigrationPreviewStep>): MigrationPreviewStep {
	return { name: 'Version2040Date20260201000000', actions: [], tables: [], dataStep: false, describes: true, ...over }
}

function mountWith (preview: MigrationPreview | null) {
	return mount(MigrationPreviewPanel, { props: { preview } })
}

describe('MigrationPreviewPanel', () => {
	it('lists a step with its action, table rows and advice for each size class', () => {
		const wrapper = mountWith({
			steps: [step({
				actions: [{ attribute: 'AddIndex', table: 'openregister_objects', name: '', description: '' }],
				tables: [
					{ name: 'openregister_objects', rows: 4200000, sizeClass: 'very_large' },
					{ name: 'openregister_logs', rows: 250000, sizeClass: 'large' },
					{ name: 'openregister_tags', rows: 12, sizeClass: 'small' },
					{ name: 'openregister_new', rows: null, sizeClass: 'new' },
				],
			})],
			repairSteps: [],
			estimated: false,
		})

		expect(wrapper.findAll('[data-testid="migration-preview-step"]')).toHaveLength(1)
		expect(wrapper.find('[data-testid="migration-preview-actions"]').text()).toBe('adds an index')
		const tables = wrapper.findAll('[data-testid="migration-preview-table"]')
		expect(tables).toHaveLength(4)
		expect(tables[0].text()).toContain('openregister_objects')
		expect(tables[0].find('[data-testid="migration-preview-rows"]').text()).toBe(`${(4200000).toLocaleString()} rows`)
		expect(tables[0].find('[data-testid="migration-preview-advice"]').text()).toBe('May take long; plan a maintenance window')
		expect(tables[1].find('[data-testid="migration-preview-advice"]').text()).toBe('May take minutes')
		expect(tables[2].find('[data-testid="migration-preview-advice"]').text()).toBe('Takes seconds')
		expect(tables[3].find('[data-testid="migration-preview-rows"]').exists()).toBe(false)
		expect(tables[3].find('[data-testid="migration-preview-advice"]').text()).toBe('New table; fast')
		expect(wrapper.find('[data-testid="migration-preview-none"]').exists()).toBe(false)
	})

	it('marks a data step and a step that does not describe its changes', () => {
		const wrapper = mountWith({
			steps: [
				step({ name: 'Version1', dataStep: true }),
				step({ name: 'Version2', describes: false }),
			],
			repairSteps: [],
			estimated: false,
		})

		expect(wrapper.findAll('[data-testid="migration-preview-data-step"]')).toHaveLength(1)
		expect(wrapper.find('[data-testid="migration-preview-data-step"]').text()).toBe('Moves data; the time depends on the amount of data.')
		expect(wrapper.find('[data-testid="migration-preview-undescribed"]').text()).toBe('This step does not describe what it changes.')
	})

	it('lists the repair steps', () => {
		const wrapper = mountWith({ steps: [step({})], repairSteps: ['OCA\\App\\Repair\\Seed', 'OCA\\App\\Repair\\Index'], estimated: false })

		const repair = wrapper.find('[data-testid="migration-preview-repair-steps"]')
		expect(repair.findAll('li').map((item) => item.text())).toEqual(['OCA\\App\\Repair\\Seed', 'OCA\\App\\Repair\\Index'])
	})

	it('says when the list is an estimate', () => {
		const wrapper = mountWith({ steps: [step({})], repairSteps: [], estimated: true })

		expect(wrapper.find('[data-testid="migration-preview-estimated"]').text()).toBe('Estimate: the list of migrations already run could not be read.')
	})

	it('says there are no database changes and still lists the repair steps', () => {
		const wrapper = mountWith({ steps: [], repairSteps: ['OCA\\App\\Repair\\Seed'], estimated: false })

		expect(wrapper.find('[data-testid="migration-preview-none"]').text()).toBe('No database changes')
		expect(wrapper.find('[data-testid="migration-preview-estimated"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="migration-preview-repair-steps"]').exists()).toBe(true)
	})

	it('says when the target could not be read', () => {
		const wrapper = mountWith(null)

		expect(wrapper.find('[data-testid="migration-preview-unreadable"]').text()).toBe('The database changes of this version could not be read.')
		expect(wrapper.find('[data-testid="migration-preview-step"]').exists()).toBe(false)
	})
})
