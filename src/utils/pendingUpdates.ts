// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { n, t } from '@nextcloud/l10n'

/** One app's entry in the snapshot GET /api/updates serves. */
export type PendingUpdate = {
	installedVersion: string
	newestVersion: string | null
	newestCompatibleVersion: string | null
	linesBehind: number
	updateAvailable: boolean
	pinned: boolean
	sourceId: string | null
	error: string | null
}

export type UpdatesFilter = 'all' | 'update' | 'outsidePolicy'

export type UpdateBadge = {
	kind: 'update' | 'pinned' | 'notChecked'
	label: string
	title?: string
}

/**
 * The badge an app card shows for its pending update, or null for none. An
 * app missing from the snapshot gets no badge rather than "up to date": before
 * the first sweep nothing is known.
 *
 * @param entry The app's snapshot entry, if the snapshot has one.
 * @spec openspec/specs/pending-updates/spec.md#requirement-every-app-card-shows-its-installed-version-and-whether-an-update-is-available
 */
export function updateBadge (entry: PendingUpdate | undefined): UpdateBadge | null {
	if (!entry) {
		return null
	}
	if (entry.error) {
		return { kind: 'notChecked', label: t('versioniq', 'Updates not checked'), title: entry.error }
	}
	if (!entry.updateAvailable || !entry.newestCompatibleVersion) {
		return null
	}
	if (entry.pinned) {
		return { kind: 'pinned', label: t('versioniq', 'Update available: {version}, held by the pin', { version: entry.newestCompatibleVersion }) }
	}

	return { kind: 'update', label: t('versioniq', 'Update available: {version}', { version: entry.newestCompatibleVersion }) }
}

/**
 * True when the app is more release lines behind than the admin allows.
 *
 * @param entry The app's snapshot entry, if the snapshot has one.
 * @param maxLinesBehind The admin's lag limit; null means the check is off.
 * @spec openspec/specs/pending-updates/spec.md#requirement-an-admin-sets-how-far-an-app-may-fall-behind
 */
export function isOutsidePolicy (entry: PendingUpdate | undefined, maxLinesBehind: number | null): boolean {
	return Boolean(entry) && maxLinesBehind !== null && !entry?.error && (entry?.linesBehind ?? 0) > maxLinesBehind
}

/**
 * @param linesBehind How many release lines the app is behind.
 * @spec openspec/specs/pending-updates/spec.md#requirement-an-admin-sets-how-far-an-app-may-fall-behind
 */
export function outsidePolicyLabel (linesBehind: number): string {
	return n('versioniq', 'Outside the update policy: %n release behind', 'Outside the update policy: %n releases behind', linesBehind)
}

/**
 * Narrows the app list by the Updates filter.
 *
 * @param apps The apps on the Apps tab.
 * @param updates The snapshot, keyed by app id.
 * @param filter The picked filter.
 * @param maxLinesBehind The admin's lag limit; null means the check is off.
 * @spec openspec/specs/pending-updates/spec.md#requirement-an-admin-sets-how-far-an-app-may-fall-behind
 */
export function filterByUpdates<T extends { id: string }> (apps: T[], updates: Record<string, PendingUpdate>, filter: UpdatesFilter, maxLinesBehind: number | null): T[] {
	if (filter === 'update') {
		return apps.filter((app) => updates[app.id]?.updateAvailable === true && !updates[app.id]?.error)
	}
	if (filter === 'outsidePolicy') {
		return apps.filter((app) => isOutsidePolicy(updates[app.id], maxLinesBehind))
	}

	return apps
}

export type UpdatesFreshnessState = {
	/** True only when the fetch itself failed. */
	unavailable: boolean
	/** Unix seconds of the last completed sweep; null means none has completed. */
	checkedAt: number | null
	/** Current time in unix seconds. */
	nowSeconds: number
}

/**
 * The line that says how old the update check is, in all three states.
 *
 * @param state The fetch outcome and the last sweep time.
 * @spec openspec/specs/pending-updates/spec.md#requirement-every-app-card-shows-its-installed-version-and-whether-an-update-is-available
 */
export function updatesFreshnessLabel (state: UpdatesFreshnessState): string {
	if (state.unavailable) {
		return t('versioniq', 'Update status unavailable. Could not reach the server.')
	}
	if (state.checkedAt === null) {
		return t('versioniq', 'Updates not checked yet. The background job runs every 6 hours.')
	}

	const ageMinutes = Math.max(0, Math.round((state.nowSeconds - state.checkedAt) / 60))
	if (ageMinutes < 1) {
		return t('versioniq', 'Updates checked just now')
	}
	if (ageMinutes < 60) {
		return t('versioniq', 'Updates checked {minutes} min ago', { minutes: ageMinutes })
	}

	return t('versioniq', 'Updates checked {hours} h ago', { hours: Math.round(ageMinutes / 60) })
}
