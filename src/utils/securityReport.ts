// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The remediation report's shape and its helpers (audit-security-reports D6).
 *
 * @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md
 */

export const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'] as const

export type SeverityFigures = {
	opened: number
	resolvedByUpdate: number
	resolvedByDismissal: number
	resolvedWithinDeadline: number | null
	withinDeadlineShare: number | null
	openPastDeadline: number | null
	openExceptions: number
	medianDays: number | null
}

export type TrendMonth = {
	month: string
	opened: number
	resolved: number
	withinDeadlineShare: number | null
	medianDays: number | null
}

export type SecurityReport = {
	severities: Record<string, SeverityFigures>
	trend: TrendMonth[]
	deadlines: Record<string, number | null>
	trackingSince: string | null
	retentionDays: number
}

/**
 * A day as YYYY-MM-DD in local time.
 *
 * @param day the day
 */
function isoDay (day: Date): string {
	const month = String(day.getMonth() + 1).padStart(2, '0')
	const date = String(day.getDate()).padStart(2, '0')
	return `${day.getFullYear()}-${month}-${date}`
}

/**
 * The first and last day of the month before the given day.
 *
 * @param today the day the page opens
 */
export function lastMonth (today: Date): { from: string, to: string } {
	const first = new Date(today.getFullYear(), today.getMonth() - 1, 1)
	const last = new Date(today.getFullYear(), today.getMonth(), 0)
	return { from: isoDay(first), to: isoDay(last) }
}

/**
 * One CSV value; empty for an unavailable figure.
 *
 * @param value the value
 */
function cell (value: number | string | null | undefined): string {
	return value === null || value === undefined ? '' : String(value)
}

/**
 * The per-severity figures and the trend as one CSV file.
 *
 * @param report the report
 */
export function reportCsv (report: SecurityReport): string {
	const lines = ['severity,opened,resolvedByUpdate,resolvedByDismissal,resolvedWithinDeadline,withinDeadlineShare,openPastDeadline,openExceptions,medianDays,deadlineDays']
	for (const severity of SEVERITIES) {
		const row = report.severities[severity]
		if (!row) {
			continue
		}
		lines.push([severity, row.opened, row.resolvedByUpdate, row.resolvedByDismissal, row.resolvedWithinDeadline, row.withinDeadlineShare, row.openPastDeadline, row.openExceptions, row.medianDays, report.deadlines[severity]].map(cell).join(','))
	}
	lines.push('', 'month,opened,resolved,withinDeadlineShare,medianDays')
	for (const month of report.trend) {
		lines.push([month.month, month.opened, month.resolved, month.withinDeadlineShare, month.medianDays].map(cell).join(','))
	}
	return lines.join('\r\n') + '\r\n'
}
