// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { t } from '@nextcloud/l10n'

/** The row key the advisory sweep uses for the Nextcloud server itself. */
export const SERVER_ADVISORY_KEY = ':server'

export type AdvisoryRecord = {
	id: string
	severity: string
	summary: string
	/** `ncsc` for an NCSC-NL record (advisories-ncsc-feed); absent for the others. */
	source?: string
	cveIds?: string[]
	ratings?: { likelihood: string | null, damage: string | null }
	published?: string
	url?: string
	/** How an NCSC-NL record's versions relate to the installed one. */
	match?: 'affected' | 'not_affected' | 'version_not_stated'
	/** The ids of records folded into this one because they share a CVE. */
	relatedIds?: string[]
}

/** How far the NCSC-NL read got, from the advisory settings (advisories-ncsc-feed). */
export type NcscStatus = {
	enabled: boolean
	readUpTo: string | null
	caughtUp: boolean
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
