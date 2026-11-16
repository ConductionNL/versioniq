// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import type { PendingUpdate } from './pendingUpdates.ts'

/** One app the Update all dialog lists. */
export type BatchCandidate = {
	appId: string
	fromVersion: string
	toVersion: string
	pinned: boolean
	/** The version the pin holds, when known. */
	pinnedVersion?: string
	/** Ticked for the run; pinned apps start unticked. */
	selected: boolean
}

export type BatchRowState = 'pending' | 'running' | 'updated' | 'upToDate' | 'failed' | 'skipped'

/** What one install of the run came back with. */
export type BatchInstallOutcome = {
	state: 'updated' | 'upToDate' | 'failed' | 'skipped'
	category?: string
	message?: string
}

export type BatchRow = {
	appId: string
	toVersion: string
	state: BatchRowState
	category?: string
	message?: string
}

export type BatchRunOptions = {
	/** Installs one app; must not throw for an install the server refused. */
	install: (candidate: BatchCandidate) => Promise<BatchInstallOutcome>
	/** Called after every state change, so the dialog can show each outcome as it lands. */
	onRow?: (row: BatchRow) => void
	/** Read before each install; true stops the run after the current one. */
	shouldStop?: () => boolean
}

export type BatchRunResult = {
	rows: BatchRow[]
	stopped: boolean
}

/**
 * The apps whose snapshot entry has a newer version this server can run, in
 * app-id order. Pinned apps are listed but unticked.
 *
 * @param updates The snapshot GET /api/updates serves, keyed by app id.
 * @spec openspec/changes/install-one-click-updates/specs/version-management/spec.md#requirement-an-admin-updates-one-app-or-all-apps-in-one-action
 */
export function updateAllCandidates (updates: Record<string, PendingUpdate>): BatchCandidate[] {
	return Object.keys(updates)
		.sort()
		.flatMap((appId) => {
			const entry = updates[appId]
			const toVersion = quickUpdateTarget(entry)
			if (!entry || toVersion === null) {
				return []
			}
			return [{
				appId,
				fromVersion: entry.installedVersion,
				toVersion,
				pinned: entry.pinned,
				selected: !entry.pinned,
			}]
		})
}

/**
 * The version "Update to {version}" installs, or null when the card offers no update.
 *
 * @param entry The app's snapshot entry, if the snapshot has one.
 * @spec openspec/changes/install-one-click-updates/specs/version-management/spec.md#requirement-an-admin-updates-one-app-or-all-apps-in-one-action
 */
export function quickUpdateTarget (entry: PendingUpdate | undefined): string | null {
	if (!entry || !offersUpdate(entry)) {
		return null
	}

	return entry.newestCompatibleVersion as string
}

/**
 * Whether a snapshot entry offers a newer compatible version than the installed one.
 *
 * @param entry The app's snapshot entry.
 */
function offersUpdate (entry: PendingUpdate): boolean {
	if (entry.error || !entry.updateAvailable || !entry.newestCompatibleVersion) {
		return false
	}

	return entry.newestCompatibleVersion !== entry.installedVersion
}

/**
 * Installs the ticked apps one after the other. A failure never stops the
 * run; a stop request lets the current install finish and marks the rest skipped.
 *
 * @param candidates The listed apps; only ticked ones are installed.
 * @param options The installer, the progress callback and the stop check.
 * @spec openspec/changes/install-one-click-updates/specs/version-management/spec.md#requirement-an-admin-updates-one-app-or-all-apps-in-one-action
 */
export async function runUpdateBatch (candidates: BatchCandidate[], options: BatchRunOptions): Promise<BatchRunResult> {
	const queue = candidates.filter((candidate) => candidate.selected)
	const rows: BatchRow[] = queue.map((candidate) => ({ appId: candidate.appId, toVersion: candidate.toVersion, state: 'pending' }))
	let stopped = false

	for (const [index, candidate] of queue.entries()) {
		const row = rows[index] as BatchRow
		stopped = stopped || options.shouldStop?.() === true
		await (stopped ? skipBatchRow(row, options) : runBatchRow(row, candidate, options))
	}

	return { rows, stopped }
}

/**
 * Marks one row of the batch skipped after a stop request and reports it.
 *
 * @param row The row to update in place.
 * @param options The progress callback.
 */
async function skipBatchRow (row: BatchRow, options: BatchRunOptions): Promise<void> {
	row.state = 'skipped'
	options.onRow?.({ ...row })
}

/**
 * Installs one row of the batch: running, then the install's outcome. Every
 * state change is reported.
 *
 * @param row The row to update in place.
 * @param candidate The app to install.
 * @param options The installer and the progress callback.
 */
async function runBatchRow (row: BatchRow, candidate: BatchCandidate, options: BatchRunOptions): Promise<void> {
	const report = (): void => options.onRow?.({ ...row })
	row.state = 'running'
	report()
	const outcome = await installOne(candidate, options.install)
	row.state = outcome.state
	row.category = outcome.category
	row.message = outcome.message
	report()
}

/**
 * Runs one install and turns a thrown error into a failed outcome.
 *
 * @param candidate The app to install.
 * @param install The installer.
 */
async function installOne (candidate: BatchCandidate, install: BatchRunOptions['install']): Promise<BatchInstallOutcome> {
	try {
		return await install(candidate)
	} catch (error) {
		return { state: 'failed', message: error instanceof Error ? error.message : String(error) }
	}
}

/** The part of an install answer the batch reads. */
export type BatchInstallResponse = {
	payload: { fromVersion?: string, toVersion?: string, category?: string, hint?: string | null, message?: string }
	metaMessage?: string
} | null

/**
 * Turns the install endpoint's final answer into the row's outcome: refused
 * is failed with its category and hint, a cancelled pin choice is skipped,
 * and the version that was already installed reads up to date.
 *
 * @param response The final answer after any pin or checksum retry; null when the admin cancelled.
 * @spec openspec/changes/install-one-click-updates/specs/version-management/spec.md#requirement-an-admin-updates-one-app-or-all-apps-in-one-action
 */
export function batchOutcome (response: BatchInstallResponse): BatchInstallOutcome {
	if (response === null) {
		return { state: 'skipped' }
	}
	const { payload, metaMessage } = response
	if (metaMessage) {
		return { state: 'failed', category: payload?.category, message: refusalMessage(payload, metaMessage) }
	}
	if (payload?.fromVersion && payload.fromVersion === payload.toVersion) {
		return { state: 'upToDate' }
	}

	return { state: 'updated' }
}

/**
 * The message of a refused install: the hint, else the payload's message, else the meta message.
 *
 * @param payload The install answer's payload.
 * @param metaMessage The OCS meta message.
 */
function refusalMessage (payload: NonNullable<BatchInstallResponse>['payload'] | undefined, metaMessage: string): string {
	return payload?.hint || payload?.message || metaMessage
}
