// SPDX-License-Identifier: EUPL-1.2
// Safe mode on the Apps tab: which versions it keeps out of the list and
// refuses to select. Extracted from App.vue so the rule has one tested home.
//
// Safe mode blocks two things (issue #434):
// - a downgrade, meaning any version older than the installed one;
// - a pre-release (alpha, beta, rc and the like) while the server follows
//   a channel that only ships stable releases. Nextcloud's own app fetcher
//   applies the same rule to store pre-releases on those channels.

// @spec openspec/specs/auto-update-policies/spec.md
import { compareVersions } from './versionCompare.ts'

export type SafeModeContext = {
	enabled: boolean
	installedVersion: string
	updateChannel: string
}

// Channels on which Nextcloud serves only stable releases. An empty or
// unknown channel filters nothing, since it cannot be said what it allows.
const STABLE_ONLY_CHANNELS = ['stable', 'production', 'enterprise']

/**
 * Whether a version string carries a semver pre-release suffix.
 *
 * @param version the version string, with or without a leading v
 */
export function isPreRelease (version: string): boolean {
	return /^v?\d+(\.\d+)*-.+/i.test(version.trim())
}

/**
 * Whether the channel only admits stable releases.
 *
 * @param channel the server update channel, as the API reports it
 */
export function isStableOnlyChannel (channel: string): boolean {
	return STABLE_ONLY_CHANNELS.includes(channel.trim().toLowerCase())
}

/**
 * Whether safe mode blocks installing `version`: it is older than the
 * installed version, or it is a pre-release while the update channel
 * admits only stable releases.
 *
 * @param version the version the admin wants to install
 * @param context safe mode state, installed version and update channel
 */
export function isBlockedBySafeMode (version: string, context: SafeModeContext): boolean {
	if (!context.enabled || !version) {
		return false
	}
	if (isStableOnlyChannel(context.updateChannel) && isPreRelease(version)) {
		return true
	}
	if (!context.installedVersion) {
		return false
	}
	return compareVersions(version, context.installedVersion) < 0
}
