// SPDX-License-Identifier: EUPL-1.2
/**
 * The facts a version entry carries (releases-version-facts): downloads, the
 * install outcomes of this instance, the publisher's breaking flag and the
 * known issues, from the notes and from admins.
 *
 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-a-version-its-publisher-marks-as-breaking-is-flagged
 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
 */
import { n, t } from '@nextcloud/l10n'
import { compareVersions, parseVersionCore } from './versionCompare.ts'

export type VersionOutcomes = { succeeded: number, failed: number }

export type AdminKnownIssue = {
	text: string
	workaround?: string | null
	link?: string | null
	addedBy?: string | null
	addedAt?: string | null
}

export type VersionFactsEntry = {
	version: string
	downloads?: number | null
	outcomes?: VersionOutcomes | null
	breaking?: boolean
	knownIssues?: string | null
	adminKnownIssues?: AdminKnownIssue[]
}

/**
 * The versions marked as breaking in the range a change from `from` to `to`
 * crosses: above the lower end, up to and including the higher end, oldest first.
 *
 * @param versions The version entries.
 * @param from The installed version.
 * @param to The chosen version.
 * @return The breaking versions in the range.
 */
export function breakingBetween (versions: VersionFactsEntry[], from: string, to: string): string[] {
	if (!from || !to || from === to) {
		return []
	}
	const [low, high] = compareVersions(from, to) < 0 ? [from, to] : [to, from]

	return versions
		.filter((entry) => entry.breaking === true
			&& compareVersions(entry.version, low) > 0
			&& compareVersions(entry.version, high) <= 0)
		.map((entry) => entry.version)
		.sort(compareVersions)
}

/**
 * The range sentence naming breaking releases, or '' when there are none.
 *
 * @param breaking The breaking versions.
 * @return The sentence.
 */
export function breakingSentence (breaking: string[]): string {
	if (breaking.length === 0) {
		return ''
	}

	return t('versioniq', 'Includes releases marked as breaking: {versions}.', { versions: breaking.join(', ') })
}

/**
 * Whether the version is a higher major than the installed one.
 *
 * @param version The version.
 * @param installed The installed version, '' when none.
 * @return True for a major step up.
 */
export function isMajorStep (version: string, installed: string): boolean {
	if (!installed) {
		return false
	}

	return parseVersionCore(version).major > parseVersionCore(installed).major
}

/**
 * "312 downloads", or '' when the source publishes no count.
 *
 * @param downloads The count, or null.
 * @return The text.
 */
export function downloadsText (downloads: number | null | undefined): string {
	if (typeof downloads !== 'number' || !Number.isFinite(downloads) || downloads < 0) {
		return ''
	}

	return n('versioniq', '{count} download', '{count} downloads', downloads, { count: downloads })
}

/**
 * How installs of the version went on this instance, or '' when it was never installed here.
 *
 * @param outcomes The counts, or null.
 * @return The text.
 */
export function outcomesText (outcomes: VersionOutcomes | null | undefined): string {
	const succeeded = outcomes?.succeeded ?? 0
	const failed = outcomes?.failed ?? 0
	const installs = succeeded + failed
	if (installs === 0) {
		return ''
	}
	const installed = n('versioniq', 'Installed {count} time here', 'Installed {count} times here', installs, { count: installs })
	if (failed === 0) {
		return installed
	}

	return t('versioniq', '{installed}, {failures}', {
		installed,
		failures: n('versioniq', '{count} failure', '{count} failures', failed, { count: failed }),
	})
}
