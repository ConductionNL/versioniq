// SPDX-License-Identifier: EUPL-1.2
// The version picker's release channel: which versions it lists, how an
// off-channel version is tagged, and when the long-term support channel has
// no line to offer. Kept out of App.vue so the rule has one tested home.
// @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md
import { t } from '@nextcloud/l10n'

export type ReleaseChannel = 'server' | 'stable' | 'beta' | 'lts'

export const RELEASE_CHANNELS: ReleaseChannel[] = ['server', 'stable', 'beta', 'lts']

export type ChannelVersion = {
	version: string
	preRelease?: boolean
	lts?: boolean
	onChannel?: boolean
}

// The versions the picker lists: those on the app's channel, or every
// version when "Show all versions" is on. A version the server did not
// stamp (an older server) is listed.
/**
 * The versions the picker lists.
 *
 * @param versions the stamped versions
 * @param showAll whether "Show all versions" is on
 */
export function pickerVersions<T extends ChannelVersion> (versions: T[], showAll: boolean): T[] {
	if (showAll) {
		return versions
	}
	return versions.filter((version) => version.onChannel !== false)
}

// The tags of a version revealed by "Show all versions": why it is off the channel.
/**
 * Why a revealed version is off the channel.
 *
 * @param version the version
 * @param channel the effective channel
 */
export function offChannelTags (version: ChannelVersion, channel: string): string[] {
	if (version.onChannel !== false) {
		return []
	}
	const tags: string[] = []
	if (version.preRelease === true) {
		tags.push(t('versioniq', 'Pre-release'))
	}
	if (channel === 'lts' && version.lts !== true) {
		tags.push(t('versioniq', 'Not long-term support'))
	}
	return tags
}

// Whether the app follows the long-term support channel without a single
// marked line, by an admin or by a publisher's release.
/**
 * Whether the long-term support channel has no marked line.
 *
 * @param channel the effective channel
 * @param adminLines the lines an admin marked
 * @param versions the stamped versions
 */
export function noLtsLinesMarked (channel: string, adminLines: string[], versions: ChannelVersion[]): boolean {
	return channel === 'lts' && adminLines.length === 0 && !versions.some((version) => version.lts === true)
}

// A channel's name as the page shows it.
/**
 * A channel's display name.
 *
 * @param channel the channel
 */
export function channelLabel (channel: string): string {
	switch (channel) {
	case 'server':
		return t('versioniq', 'Same as the server')
	case 'stable':
		return t('versioniq', 'Stable')
	case 'beta':
		return t('versioniq', 'Beta (includes pre-releases)')
	case 'lts':
		return t('versioniq', 'Long-term support')
	default:
		return channel
	}
}

// Parse the long-term support lines field: `2.3, 3` into ['2.3', '3'].
/**
 * Parse the lines field.
 *
 * @param text the field's text
 */
export function parseLtsLines (text: string): string[] {
	return text.split(',').map((line) => line.trim()).filter((line) => line !== '')
}
