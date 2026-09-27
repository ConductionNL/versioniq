// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { compareVersions } from './versionCompare.ts'

export type InstallDebugEntry = {
	stage: string
	data?: unknown
}

export type InstallResult = {
	appId: string
	fromVersion?: string | null
	toVersion: string
	installedVersion?: string | null
	updateType?: string
	message: string
	dryRun: boolean
	installStatus: string
	stage?: string | null
	category?: string | null
	hint?: string | null
	debug?: InstallDebugEntry[]
	recordedShaMatched?: boolean | null
	orphanedMigrations?: string[] | null
	/** Set when an external install went ahead without checksum verification (#438). */
	integrityWarning?: string | null
	/** True when the artifact came from the local cache rather than the source (#438). */
	servedFromCache?: boolean
}

export type InstallPayload = {
	appId?: string
	fromVersion?: string | null
	toVersion?: string
	installedVersion?: string | null
	updateType?: string
	message?: string
	dryRun?: boolean
	installStatus?: string
	stage?: string | null
	category?: string | null
	hint?: string | null
	debug?: unknown
	recordedShaMatched?: boolean
	integrityWarning?: string | null
	servedFromCache?: boolean
}

/**
 * Turns the installer's payload into the shape the result panel renders. It
 * keeps `integrityWarning` and `servedFromCache`: both were in the payload and
 * dropped here, so an unverified install looked like a verified one (#438).
 *
 * @param payload The install endpoint's payload.
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/specs/external-sources/spec.md
 */
export function normalizeInstallResult (payload: InstallPayload): InstallResult {
	const normalizedUpdateType = payload.updateType ?? 'none'
	const normalizedFrom = payload.fromVersion ?? null
	const normalizedTo = payload.toVersion || ''
	const resolvedMessage = payload.message || 'Install completed.'
	const shouldForceDowngradeMessage = normalizedUpdateType === 'downgrade'
		|| (normalizedFrom !== null && normalizedTo !== '' && compareVersions(normalizedTo, normalizedFrom) < 0)
	const finalMessage = shouldForceDowngradeMessage
		? (resolvedMessage === 'App updated.'
			? 'App downgraded.'
			: resolvedMessage)
		: resolvedMessage

	return {
		appId: payload.appId || '',
		fromVersion: normalizedFrom,
		toVersion: normalizedTo,
		installedVersion: payload.installedVersion ?? null,
		updateType: normalizedUpdateType,
		message: finalMessage,
		dryRun: Boolean(payload.dryRun),
		installStatus: payload.installStatus || 'failed',
		stage: payload.stage ?? null,
		category: payload.category ?? null,
		hint: payload.hint ?? null,
		debug: Array.isArray(payload.debug) ? payload.debug as InstallDebugEntry[] : [],
		recordedShaMatched: payload.recordedShaMatched ?? null,
		integrityWarning: typeof payload.integrityWarning === 'string' && payload.integrityWarning !== '' ? payload.integrityWarning : null,
		servedFromCache: payload.servedFromCache === true,
	}
}

/**
 * Adds a one-off `source` to a versions or install query when the admin set
 * one; the app's stored binding is left as it is (#438).
 *
 * @param query The query parameters so far.
 * @param source The override, for example `github:owner/repo`; blank means none.
 * @spec openspec/specs/external-sources/spec.md
 */
export function withSourceOverride (query: Record<string, string>, source: string): Record<string, string> {
	const trimmed = source.trim()
	return trimmed === '' ? { ...query } : { ...query, source: trimmed }
}
