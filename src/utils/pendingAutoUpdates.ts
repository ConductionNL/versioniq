// SPDX-License-Identifier: EUPL-1.2
/**
 * What the next automatic update window will do with each app that has a
 * policy, from the stored availability sweep: the newer version available
 * now and whether it is scheduled, held by the pin, not covered by the policy
 * level, or skipped because an earlier attempt failed.
 *
 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/auto-update-policies/spec.md#requirement-the-overview-lists-the-updates-that-are-pending-now
 */
import type { PendingUpdate } from './pendingUpdates.ts'

import { compareVersions, parseVersionCore } from './versionCompare.ts'

export type PolicyLevel = 'none' | 'patch' | 'minor' | 'all'

export type PendingState = 'scheduled' | 'pinned' | 'outsidePolicy' | 'failed'

export type PendingRow = { appId: string, version: string, state: PendingState }

type PolicyLike = { appId?: string, level: PolicyLevel, blockedVersions?: { version: string }[] }

/**
 * Whether a policy level lets an update go from one version to another.
 *
 * @param level The policy level.
 * @param from The installed version.
 * @param to The newer version.
 */
export function levelAllows (level: PolicyLevel, from: string, to: string): boolean {
	const a = parseVersionCore(from)
	const b = parseVersionCore(to)
	if (level === 'all') {
		return true
	}
	if (level === 'minor') {
		return a.major === b.major
	}
	if (level === 'patch') {
		return a.major === b.major && a.minor === b.minor
	}
	return false
}

/**
 * The pending update of every app with a policy and a newer version, by app id.
 *
 * @param policies The policies, by app id.
 * @param updates The stored availability sweep, by app id.
 */
export function pendingAutoUpdates (policies: Record<string, PolicyLike>, updates: Record<string, PendingUpdate>): PendingRow[] {
	const rows: PendingRow[] = []
	for (const [key, policy] of Object.entries(policies)) {
		const appId = policy.appId || key
		const update = updates[appId]
		if (policy.level === 'none' || !update || update.error) {
			continue
		}
		const version = update.newestCompatibleVersion ?? update.newestVersion
		if (!version || !update.installedVersion || compareVersions(version, update.installedVersion) <= 0) {
			continue
		}
		rows.push({ appId, version, state: stateOf(policy, update, version) })
	}

	return rows.sort((a, b) => a.appId.localeCompare(b.appId))
}

/**
 * @param policy The app's policy.
 * @param update The app's stored availability.
 * @param version The newer version.
 */
function stateOf (policy: PolicyLike, update: PendingUpdate, version: string): PendingState {
	if (update.pinned) {
		return 'pinned'
	}
	if ((policy.blockedVersions ?? []).some((entry) => entry.version === version)) {
		return 'failed'
	}
	return levelAllows(policy.level, update.installedVersion, version) ? 'scheduled' : 'outsidePolicy'
}
