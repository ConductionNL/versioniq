// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { n, t } from '@nextcloud/l10n'

/** The row key the advisory sweep uses for the Nextcloud server itself. */
export const SERVER_ADVISORY_KEY = ':server'

/** How many of a target's advisories show before "Show all". */
export const RECENT_ADVISORIES = 5

/** The anchor prefix of an app's block on the Advisories tab. */
export const ADVISORY_ANCHOR_PREFIX = 'section-advisories-'

export type AdvisoryRecord = {
	id: string
	severity: string
	summary: string
	cveIds?: string[]
	publishedAt?: string | null
	url?: string | null
}

export type AdvisoryHistoryEntry = {
	id: string
	severity: string
	summary: string
	cveIds: string[]
	publishedAt: string | null
	url: string | null
	affectsInstalled: boolean
}

export type FixStatus = 'installable' | 'no_fix_published' | 'not_in_source' | 'needs_newer_server'

export type AdvisoryCorrelation = {
	appId: string
	installedVersion: string | null
	state: 'none' | 'advisory-available' | 'pinned-to-vulnerable'
	advisories: AdvisoryRecord[]
	recommendedVersion: string | null
	error: string | null
	history?: AdvisoryHistoryEntry[]
	fixStatus?: FixStatus | null
	heldByPin?: boolean
}

export type Compliance = {
	status: 'compliant' | 'not_compliant' | 'unknown'
	reason: 'never_checked' | 'stale' | 'unreached' | null
	affected: number
	bySeverity: Record<string, number> | []
	affectedIds: string[]
	unreached: number
	checkedAt: number | null
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

/**
 * The anchor of an app's block on the Advisories tab; the card badge links to it.
 *
 * @param appId The app id.
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
 */
export function advisoryAnchor (appId: string): string {
	return ADVISORY_ANCHOR_PREFIX + appId
}

/**
 * A target's advisories newest first, each marked as affecting the installed
 * version or not. A snapshot from before the history existed has only
 * `advisories`; those read as undated, and as affecting when the row does.
 *
 * @param row The target's advisory correlation.
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
 */
export function advisoryHistory (row: AdvisoryCorrelation): AdvisoryHistoryEntry[] {
	if (Array.isArray(row.history)) {
		return row.history
	}
	const affects = row.state === 'pinned-to-vulnerable'
	return row.advisories.map((advisory) => ({
		id: advisory.id,
		severity: advisory.severity,
		summary: advisory.summary,
		cveIds: advisory.cveIds ?? [],
		publishedAt: advisory.publishedAt ?? null,
		url: advisory.url ?? null,
		affectsInstalled: affects,
	}))
}

/**
 * The CVE ids of an advisory as one line, or "No CVE id".
 *
 * @param entry The history entry.
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
 */
export function cveLabel (entry: Pick<AdvisoryHistoryEntry, 'cveIds'>): string {
	return entry.cveIds.length > 0 ? entry.cveIds.join(', ') : t('versioniq', 'No CVE id')
}

/**
 * The publication date of an advisory as a calendar date, or "Date unknown".
 *
 * @param entry The history entry.
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
 */
export function publishedLabel (entry: Pick<AdvisoryHistoryEntry, 'publishedAt'>): string {
	return entry.publishedAt ? entry.publishedAt.slice(0, 10) : t('versioniq', 'Date unknown')
}

/**
 * Why an affected target has, or has not, an installable fix, in one
 * sentence; empty when the target is not affected.
 *
 * @param row The target's advisory correlation.
 * @param isServer Whether the row is the Nextcloud server's own.
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-an-affected-version-without-an-installable-fix-says-why
 */
export function fixSentence (row: AdvisoryCorrelation, isServer = false): string {
	if (row.state !== 'pinned-to-vulnerable') {
		return ''
	}
	const version = row.recommendedVersion ?? ''
	if (row.heldByPin === true) {
		return t('versioniq', 'A pin holds this app below the fix {version}.', { version })
	}
	switch (row.fixStatus) {
	case 'installable':
		return isServer
			? t('versioniq', 'Upgrade Nextcloud to {version} or later.', { version })
			: t('versioniq', 'Install {version} to fix it.', { version })
	case 'no_fix_published':
		return t('versioniq', 'No fixed version is published yet.')
	case 'not_in_source':
		return t('versioniq', 'The fixed version {version} is not offered by the source this app installs from.', { version })
	case 'needs_newer_server':
		return t('versioniq', 'The fix needs a newer Nextcloud than this server runs.')
	default:
		return ''
	}
}

/**
 * The patch compliance status of the instance in one line.
 *
 * @param compliance The status from GET /api/advisories, or null before it loaded.
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-the-instance-has-one-patch-compliance-status
 */
export function complianceSentence (compliance: Compliance | null): string {
	if (compliance === null) {
		return ''
	}
	if (compliance.status === 'compliant') {
		return t('versioniq', 'Compliant')
	}
	if (compliance.status === 'not_compliant') {
		const counts = Object.entries(Array.isArray(compliance.bySeverity) ? {} : compliance.bySeverity)
			.map(([severity, count]) => t('versioniq', '{count} {severity}', { count: String(count), severity: severityLabel(severity).toLowerCase() }))
			.join(', ')
		return n(
			'versioniq',
			'Not compliant: %n app runs an affected version ({counts})',
			'Not compliant: %n apps run an affected version ({counts})',
			compliance.affected,
			{ counts },
		)
	}
	if (compliance.reason === 'never_checked') {
		return t('versioniq', 'Compliance unknown: no advisory check has completed yet')
	}
	if (compliance.reason === 'stale') {
		return t('versioniq', 'Compliance unknown: the last advisory check is out of date')
	}
	return n(
		'versioniq',
		'Compliance unknown: %n app could not be checked',
		'Compliance unknown: %n apps could not be checked',
		compliance.unreached,
	)
}
