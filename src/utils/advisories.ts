// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { t } from '@nextcloud/l10n'

/** The row key the advisory sweep uses for the Nextcloud server itself. */
export const SERVER_ADVISORY_KEY = ':server'

export type AdvisoryRecord = {
	id: string
	severity: string
	summary: string
}

export type AdvisoryCorrelation = {
	appId: string
	installedVersion: string | null
	state: 'none' | 'advisory-available' | 'pinned-to-vulnerable'
	advisories: AdvisoryRecord[]
	recommendedVersion: string | null
	error: string | null
}

/** The documented levels, least to most severe (AdvisorySeverity.php). */
const SEVERITY_ORDER = ['unknown', 'low', 'medium', 'high', 'critical']

/**
 * The documented level for a raw value; anything else is `unknown`.
 *
 * @param severity The severity as the API sent it.
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
export function normalizeSeverity (severity: string | null | undefined): string {
	const value = (severity ?? '').toLowerCase()
	return SEVERITY_ORDER.includes(value) ? value : 'unknown'
}

/**
 * The most severe level among the advisories, or null when there are none.
 *
 * @param advisories Advisory records carrying a severity.
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
export function highestSeverity (advisories: Array<{ severity: string }>): string | null {
	if (advisories.length === 0) {
		return null
	}
	return advisories
		.map((advisory) => normalizeSeverity(advisory.severity))
		.reduce((top, level) => (SEVERITY_ORDER.indexOf(level) > SEVERITY_ORDER.indexOf(top) ? level : top), 'unknown')
}

/**
 * A readable name for a severity level.
 *
 * @param severity The severity as the API sent it.
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
export function severityLabel (severity: string): string {
	return {
		low: t('versioniq', 'Low'),
		medium: t('versioniq', 'Medium'),
		high: t('versioniq', 'High'),
		critical: t('versioniq', 'Critical'),
	}[normalizeSeverity(severity)] ?? t('versioniq', 'Unknown severity')
}

/**
 * The text of an app card's advisory badge: the state and the highest
 * severity, so a critical and a low advisory no longer look the same.
 *
 * @param row The app's advisory correlation.
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
export function advisoryBadgeText (row: AdvisoryCorrelation): string {
	const label = row.state === 'pinned-to-vulnerable'
		? t('versioniq', 'Vulnerable version')
		: t('versioniq', 'Advisory')
	const top = highestSeverity(row.advisories)
	if (top === null) {
		return label
	}
	return t('versioniq', '{label}, {severity}', { label, severity: severityLabel(top) })
}

/**
 * Splits the advisory snapshot into the server's own row and the app rows
 * worth showing (anything with advisories or an error), vulnerable first.
 *
 * @param snapshot The per-app map from GET /api/advisories.
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
export function splitAdvisoryRows (snapshot: Record<string, AdvisoryCorrelation>): { server: AdvisoryCorrelation | null, apps: AdvisoryCorrelation[] } {
	const server = snapshot[SERVER_ADVISORY_KEY] ?? null
	const apps = Object.entries(snapshot)
		.filter(([key, row]) => key !== SERVER_ADVISORY_KEY && (row.advisories.length > 0 || row.error))
		.map(([key, row]) => ({ ...row, appId: row.appId || key }))
		.sort((a, b) => {
			const va = a.state === 'pinned-to-vulnerable' ? 0 : 1
			const vb = b.state === 'pinned-to-vulnerable' ? 0 : 1
			return va !== vb ? va - vb : a.appId.localeCompare(b.appId)
		})
	return { server, apps }
}
