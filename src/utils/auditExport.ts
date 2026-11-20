// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The History tab's export (audit-security-reports D5): every page for the
 * filter, at most EXPORT_LIMIT rows, as CSV or JSON, with the columns shown
 * on screen plus the fixed advisory ids.
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */

export const EXPORT_LIMIT = 5000
export const EXPORT_PAGE_SIZE = 200

export type ExportEntry = {
	id: number
	actorUid: string
	appId: string
	operation: string
	fromVersion: string | null
	toVersion: string | null
	sourceId: string | null
	status: string
	message: string | null
	createdAt: string
	fixedAdvisories?: Array<{ id: string, severity: string }>
}

export const CSV_COLUMNS = ['createdAt', 'actorUid', 'appId', 'operation', 'fromVersion', 'toVersion', 'sourceId', 'status', 'message', 'fixedAdvisories'] as const

/**
 * Read pages until one comes back short or the limit is reached.
 *
 * @param fetchPage reads one page at an offset
 * @return the rows and whether the limit cut them
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */
export async function collectAll (fetchPage: (offset: number, limit: number) => Promise<ExportEntry[]>): Promise<{ rows: ExportEntry[], cut: boolean }> {
	const rows: ExportEntry[] = []
	while (rows.length < EXPORT_LIMIT) {
		const limit = Math.min(EXPORT_PAGE_SIZE, EXPORT_LIMIT - rows.length)
		const page = await fetchPage(rows.length, limit)
		rows.push(...page)
		if (page.length < limit) {
			return { rows, cut: false }
		}
	}

	const next = await fetchPage(rows.length, 1)
	return { rows, cut: next.length > 0 }
}

/**
 * One CSV field, quoted when it holds a separator, a quote or a line break.
 *
 * @param value the value
 */
function csvField (value: string): string {
	return /[",\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value
}

/**
 * The rows as CSV with a header line.
 *
 * @param rows the entries
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */
export function toCsv (rows: ExportEntry[]): string {
	const lines = [CSV_COLUMNS.join(',')]
	for (const row of rows) {
		lines.push(CSV_COLUMNS.map((column) => {
			if (column === 'fixedAdvisories') {
				return csvField((row.fixedAdvisories ?? []).map((advisory) => advisory.id).join(' '))
			}
			const value = row[column]
			return csvField(value === null || value === undefined ? '' : String(value))
		}).join(','))
	}
	return lines.join('\r\n') + '\r\n'
}

/**
 * The rows as pretty JSON.
 *
 * @param rows the entries
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */
export function toJson (rows: ExportEntry[]): string {
	return JSON.stringify(rows, null, 2) + '\n'
}

/**
 * Offer a text as a file download.
 *
 * @param name the file name
 * @param type the media type
 * @param content the file's text
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */
export function download (name: string, type: string, content: string): void {
	const url = URL.createObjectURL(new Blob([content], { type }))
	const link = document.createElement('a')
	link.href = url
	link.download = name
	document.body.appendChild(link)
	link.click()
	link.remove()
	URL.revokeObjectURL(url)
}
