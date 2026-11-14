import type { SecurityReport } from '../utils/securityReport.ts'

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Covers the remediation report on the History tab:
// @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecurityReportPanel from './SecurityReportPanel.vue'
import { ocsGet } from '../ocs.ts'
import { lastMonth, reportCsv } from '../utils/securityReport.ts'

const downloads = vi.hoisted(() => [] as Array<{ name: string, type: string, content: string }>)

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue/components/NcButton', () => ({ default: { name: 'NcButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' } }))
vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({ default: { name: 'NcLoadingIcon', template: '<div />' } }))
vi.mock('@nextcloud/vue/components/NcNoteCard', () => ({ default: { name: 'NcNoteCard', template: '<div><slot /></div>' } }))
vi.mock('../ocs', () => ({ ocsGet: vi.fn() }))
vi.mock('../utils/auditExport.ts', () => ({
	download: (name: string, type: string, content: string) => downloads.push({ name, type, content }),
}))

const mockedOcsGet = vi.mocked(ocsGet)

function figures (extra: Record<string, unknown> = {}) {
  return {
	opened: 0, resolvedByUpdate: 0, resolvedByDismissal: 0, resolvedWithinDeadline: null, withinDeadlineShare: null, openPastDeadline: null, openExceptions: 0, medianDays: null, ...extra,
}
}

function report (deadlines: Record<string, number | null>, high: Record<string, unknown>): SecurityReport {
  return {
	severities: { critical: figures(), high: figures(high), medium: figures(), low: figures(), unknown: figures() },
	trend: [{ month: '2026-10', opened: 2, resolved: 1, withinDeadlineShare: null, medianDays: 3.5 }],
	deadlines: { critical: null, high: null, medium: null, low: null, unknown: null, ...deadlines },
	trackingSince: '2026-09-01 00:00:00',
	retentionDays: 365,
}
}

describe('SecurityReportPanel', () => {
	beforeEach(() => {
		mockedOcsGet.mockReset()
		downloads.length = 0
	})

	it('asks for last month and renders the null share with the patch policy hint', async () => {
		mockedOcsGet.mockResolvedValue({ payload: report({}, { opened: 1, resolvedByUpdate: 1, medianDays: 3 }) })
		const wrapper = mount(SecurityReportPanel)
		await flushPromises()

		const [path, query] = mockedOcsGet.mock.calls[0] ?? []
		expect(path).toBe('/ocs/v2.php/apps/versioniq/api/reports/security')
		expect(query).toMatchObject(lastMonth(new Date()))
		const high = wrapper.find('[data-severity="high"]')
		expect(high.findAll('td').map((td) => td.text())).toEqual(['1', '1', '0', '—', '—', '3'])
		expect(wrapper.find('[data-testid="report-deadline-hint"]').text())
			.toBe('No deadline is set for Critical, High, Medium, Low, Unknown, so the share fixed within the deadline is unavailable. Set deadlines in the patch policy.')
		expect(wrapper.find('[data-testid="report-coverage"]').text()).toBe('Tracking since 2026-09-01. The history keeps 365 days.')
		expect(wrapper.find('[data-month="2026-10"]').findAll('td').map((td) => td.text())).toEqual(['2', '1', '—', '3.5'])
	})

	it('shows the share when a deadline is set and names only the severities without one', async () => {
		mockedOcsGet.mockResolvedValue({ payload: report({ critical: 2, high: 7, medium: 30, low: 90 }, { withinDeadlineShare: 0.5, openPastDeadline: 1 }) })
		const wrapper = mount(SecurityReportPanel)
		await flushPromises()

		expect(wrapper.find('[data-severity="high"] [data-cell="within"]').text()).toBe('50%')
		expect(wrapper.find('[data-testid="report-deadline-hint"]').text()).toContain('No deadline is set for Unknown,')
	})

	it('asks again for a new period and exports the tables as CSV', async () => {
		const payload = report({}, { opened: 1 })
		mockedOcsGet.mockResolvedValue({ payload })
		const wrapper = mount(SecurityReportPanel)
		await flushPromises()

		await wrapper.find('[data-testid="report-from"]').setValue('2026-11-01')
		await wrapper.find('[data-testid="report-to"]').setValue('2026-11-30')
		await flushPromises()
		expect(mockedOcsGet.mock.calls.at(-1)?.[1]).toMatchObject({ from: '2026-11-01', to: '2026-11-30' })

		await wrapper.find('[data-testid="report-export-csv"]').trigger('click')
		expect(downloads).toEqual([{ name: 'versioniq-security-report-2026-11-01-2026-11-30.csv', type: 'text/csv', content: reportCsv(payload) }])
		expect(reportCsv(payload).split('\r\n').slice(0, 3)).toEqual([
			'severity,opened,resolvedByUpdate,resolvedByDismissal,resolvedWithinDeadline,withinDeadlineShare,openPastDeadline,openExceptions,medianDays,deadlineDays',
			'critical,0,0,0,,,,0,,',
			'high,1,0,0,,,,0,,',
		])
	})

	it('shows a refusal and no tables', async () => {
		mockedOcsGet.mockResolvedValue({ payload: {} as SecurityReport, error: 'Forbidden' })
		const wrapper = mount(SecurityReportPanel)
		await flushPromises()

		expect(wrapper.text()).toContain('Forbidden')
		expect(wrapper.find('[data-testid="report-severities"]').exists()).toBe(false)
	})
})

describe('lastMonth', () => {
	it('is the whole previous month, across a year boundary too', () => {
		expect(lastMonth(new Date(2026, 10, 14))).toEqual({ from: '2026-10-01', to: '2026-10-31' })
		expect(lastMonth(new Date(2026, 0, 5))).toEqual({ from: '2025-12-01', to: '2025-12-31' })
	})
})
