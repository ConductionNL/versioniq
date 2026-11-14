// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { compareVersions } from './versionCompare.ts'

export type InstallDebugEntry = {
	stage: string
	data?: unknown
}

/** One update check of a dry run (safety-update-checks). */
export type CheckResult = {
	name: string
	kind: 'verdict' | 'command' | string
	outcome: 'passed' | 'failed' | 'skipped' | string
	passed: boolean
	detail: string
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
	/** The update checks a dry run ran; present only when the payload carried them. */
	checks?: CheckResult[]
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
	checks?: unknown
}

/**
 * Tells whether the payload's target version is below its source version.
 *
 * @param payload The install endpoint's payload.
 */
function versionDropped (payload: InstallPayload): boolean {
	const from = payload.fromVersion ?? null
	const to = payload.toVersion || ''
	return from !== null && to !== '' && compareVersions(to, from) < 0
}

/**
 * The message to show: the installer's own, with "App updated." reworded for
 * a downgrade.
 *
 * @param payload The install endpoint's payload.
 */
function installMessage (payload: InstallPayload): string {
	const message = payload.message || 'Install completed.'
	const downgrade = payload.updateType === 'downgrade' || versionDropped(payload)
	return downgrade && message === 'App updated.' ? 'App downgraded.' : message
}

/**
 * The version fields of the result.
 *
 * @param payload The install endpoint's payload.
 */
function versionFields (payload: InstallPayload): Pick<InstallResult, 'fromVersion' | 'toVersion' | 'installedVersion' | 'updateType'> {
	return {
		fromVersion: payload.fromVersion ?? null,
		toVersion: payload.toVersion || '',
		installedVersion: payload.installedVersion ?? null,
		updateType: payload.updateType ?? 'none',
	}
}

/**
 * The status fields of the result: dry run, status, stage, category and hint.
 *
 * @param payload The install endpoint's payload.
 */
function statusFields (payload: InstallPayload): Pick<InstallResult, 'dryRun' | 'installStatus' | 'stage' | 'category' | 'hint'> {
	return {
		dryRun: Boolean(payload.dryRun),
		installStatus: payload.installStatus || 'failed',
		stage: payload.stage ?? null,
		category: payload.category ?? null,
		hint: payload.hint ?? null,
	}
}

/**
 * The evidence fields of the result: debug log, checksum match, integrity
 * warning and cache origin.
 *
 * @param payload The install endpoint's payload.
 */
function evidenceFields (payload: InstallPayload): Pick<InstallResult, 'debug' | 'recordedShaMatched' | 'integrityWarning' | 'servedFromCache'> {
	return {
		debug: Array.isArray(payload.debug) ? payload.debug as InstallDebugEntry[] : [],
		recordedShaMatched: payload.recordedShaMatched ?? null,
		integrityWarning: typeof payload.integrityWarning === 'string' && payload.integrityWarning !== '' ? payload.integrityWarning : null,
		servedFromCache: payload.servedFromCache === true,
	}
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
	return {
		appId: payload.appId || '',
		...versionFields(payload),
		message: installMessage(payload),
		...statusFields(payload),
		...evidenceFields(payload),
		...checkFields(payload),
	}
}

/**
 * The update checks of a dry run, kept only when the payload carried a list.
 *
 * @param payload The install endpoint's payload.
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
function checkFields (payload: InstallPayload): Pick<InstallResult, 'checks'> {
	if (!Array.isArray(payload.checks)) {
		return {}
	}
	return {
		checks: payload.checks
			.filter((check): check is Record<string, unknown> => typeof check === 'object' && check !== null)
			.map((check) => ({
				name: String(check.name ?? ''),
				kind: String(check.kind ?? ''),
				outcome: String(check.outcome ?? ''),
				passed: check.passed === true,
				detail: String(check.detail ?? ''),
			})),
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
