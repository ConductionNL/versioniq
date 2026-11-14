// SPDX-License-Identifier: EUPL-1.2
/**
 * The versions of an app an admin skipped (pinning-ranges-and-skips): a
 * skipped version is never chosen by the nightly job and never shown as the
 * pending update; later versions arrive as usual.
 *
 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
 */
import { ocsGet, ocsWrite } from '../ocs.ts'

export type SkipEntry = { version: string, skippedBy: string, skippedAt: string, reason: string }

const base = (appId: string): string => `/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(appId)}`

/**
 * The skip list of an app by version; an unreadable list reads as none.
 *
 * @param appId The app.
 */
export async function fetchSkips (appId: string): Promise<Record<string, SkipEntry>> {
	try {
		const { payload, error } = await ocsGet<{ skipped?: SkipEntry[] }>(`${base(appId)}/skips`)
		if (error) {
			return {}
		}
		return Object.fromEntries((payload.skipped ?? []).map((entry) => [entry.version, entry]))
	} catch {
		return {}
	}
}

/**
 * Skip one version, with an optional reason.
 *
 * @param appId The app.
 * @param version The version to skip.
 * @param reason Why, shown in the version picker.
 */
export async function skipVersion (appId: string, version: string, reason: string): Promise<void> {
	const { error } = await ocsWrite('PUT', `${base(appId)}/skip/${encodeURIComponent(version)}`, { reason: reason.trim() })
	if (error) {
		throw new Error(error)
	}
}

/**
 * Take a version off the skip list.
 *
 * @param appId The app.
 * @param version The version.
 */
export async function unskipVersion (appId: string, version: string): Promise<void> {
	const { error } = await ocsWrite('DELETE', `${base(appId)}/skip/${encodeURIComponent(version)}`)
	if (error) {
		throw new Error(error)
	}
}
