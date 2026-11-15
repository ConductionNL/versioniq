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

/** The owner of an advisory: an admin user, or a group whose admins own it. */
export type AdvisoryAssignee = {
	type: 'user' | 'group'
	id: string
}

/** A recorded decision that an advisory does not apply here. */
export type AdvisoryDismissal = {
	reason: string
	comment?: string
	by: string
	at: string
	until: string | null
	installedVersion: string | null
}

/** The triage of one advisory of one app, as GET /api/advisories returns it. */
export type AdvisoryTriageEntry = {
	assignee?: AdvisoryAssignee
	assignedBy?: string
	assignedAt?: string
	dismissal?: AdvisoryDismissal
	lastSeenAt?: string
}

/** The triage map, keyed `{appId}|{advisoryId}`. */
export type AdvisoryTriageMap = Record<string, AdvisoryTriageEntry>

/** An advisory with its owner and, when dismissed, its dismissal. */
export type TriagedAdvisory = AdvisoryRecord & {
	assignee?: AdvisoryAssignee
	dismissal?: AdvisoryDismissal
}

/** A row with the triage applied: what still counts, and what a dismissal holds. */
export type TriagedCorrelation = Omit<AdvisoryCorrelation, 'advisories'> & {
	advisories: TriagedAdvisory[]
	dismissedAdvisories: TriagedAdvisory[]
}

/** The reasons an admin can give for a dismissal, in the order the dialog lists them. */
export const DISMISS_REASONS = ['not_used', 'inaccurate', 'tolerable_risk', 'mitigated', 'fix_planned'] as const

/**
 * The key of one app's advisory in the triage map.
 *
 * @param appId The app id, or `:server`.
 * @param advisoryId The advisory id.
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 */
export function triageKey (appId: string, advisoryId: string): string {
	return `${appId}|${advisoryId}`
}

/**
 * Whether a dismissal still holds: no end date or one still ahead, and the
 * version it was made on still installed. An unreadable end date has passed.
 *
 * @param dismissal The stored dismissal, if any.
 * @param installedVersion The installed version of the row.
 * @param now The time to judge the end date by, in milliseconds.
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 */
export function isDismissalActive (dismissal: AdvisoryDismissal | undefined, installedVersion: string | null, now: number): boolean {
	if (!dismissal || (dismissal.installedVersion ?? null) !== installedVersion) {
		return false
	}
	if (!dismissal.until) {
		return true
	}
	const end = Date.parse(dismissal.until)
	return !Number.isNaN(end) && end > now
}

/**
 * The row with the triage applied, as AdvisoryTriage::apply() does on the
 * server: actively dismissed advisories move to `dismissedAdvisories`, each
 * advisory carries its owner, and a row with nothing left that counts drops
 * from `pinned-to-vulnerable` to `advisory-available` (losing its
 * recommendation) or from `advisory-available` to `none`.
 *
 * @param row The stored row.
 * @param triage The triage map.
 * @param now The time to judge end dates by, in milliseconds.
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 */
export function applyTriage (row: AdvisoryCorrelation, triage: AdvisoryTriageMap, now: number = Date.now()): TriagedCorrelation {
	const kept: TriagedAdvisory[] = []
	const dismissed: TriagedAdvisory[] = []
	for (const advisory of row.advisories ?? []) {
		const entry = triage[triageKey(row.appId, advisory.id)] ?? {}
		const triaged: TriagedAdvisory = entry.assignee ? { ...advisory, assignee: entry.assignee } : { ...advisory }
		if (isDismissalActive(entry.dismissal, row.installedVersion ?? null, now)) {
			dismissed.push({ ...triaged, dismissal: entry.dismissal })
		} else {
			kept.push(triaged)
		}
	}
	const applied: TriagedCorrelation = { ...row, advisories: kept, dismissedAdvisories: dismissed }
	if (dismissed.length === 0 || kept.length > 0) {
		return applied
	}
	if (row.state === 'pinned-to-vulnerable') {
		return { ...applied, state: 'advisory-available', recommendedVersion: null }
	}
	return row.state === 'advisory-available' ? { ...applied, state: 'none' } : applied
}

/**
 * Whether an advisory is owned by the current admin: directly, or through a
 * group they are a member of.
 *
 * @param advisory The triaged advisory.
 * @param uid The current user's id.
 * @param groups The current user's groups.
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 */
export function isAssignedTo (advisory: TriagedAdvisory, uid: string, groups: string[]): boolean {
	const owner = advisory.assignee
	if (!owner) {
		return false
	}
	return owner.type === 'user' ? owner.id === uid : groups.includes(owner.id)
}

/**
 * A readable name for a dismissal reason.
 *
 * @param reason The stored reason.
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 */
export function dismissReasonLabel (reason: string): string {
	return {
		not_used: t('versioniq', 'The affected feature is not used here'),
		inaccurate: t('versioniq', 'The advisory is inaccurate for this setup'),
		tolerable_risk: t('versioniq', 'The risk is tolerable'),
		mitigated: t('versioniq', 'The risk is mitigated another way'),
		fix_planned: t('versioniq', 'A fix is planned'),
	}[reason] ?? reason
}

/**
 * A readable name for an advisory's owner.
 *
 * @param assignee The owner.
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 */
export function assigneeLabel (assignee: AdvisoryAssignee): string {
	return assignee.type === 'group'
		? t('versioniq', 'Group {group}', { group: assignee.id })
		: assignee.id
}
