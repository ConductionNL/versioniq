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
 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-a-version-its-publisher-marks-as-breaking-is-flagged
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
 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-a-version-its-publisher-marks-as-breaking-is-flagged
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
 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-a-version-its-publisher-marks-as-breaking-is-flagged
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
 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
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
 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
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

/**
 * One connected instance's answer to the outcomes read: its counts per
 * version, or why they could not be read.
 */
export type InstanceOutcomesRead = {
	id: string
	label: string
	outcomes: Record<string, VersionOutcomes> | null
	error: string | null
}

/**
 * The successful installs, failures and instances that ran the version, across
 * this instance and every connected instance that could be read.
 *
 * @param version The version.
 * @param here This instance's counts of the version, or null.
 * @param reads The connected instances' answers.
 * @return The totals.
 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
 */
export function instanceTotals (version: string, here: VersionOutcomes | null | undefined, reads: InstanceOutcomesRead[]): { installs: number, failures: number, instances: number } {
	const counts = [here, ...reads.map((read) => read.outcomes?.[version])]
	let installs = 0
	let failures = 0
	let instances = 0
	for (const count of counts) {
		const succeeded = Math.max(0, count?.succeeded ?? 0)
		installs += succeeded
		failures += Math.max(0, count?.failed ?? 0)
		instances += succeeded > 0 ? 1 : 0
	}

	return { installs, failures, instances }
}

/**
 * "Installed 4 times on 3 instances, 1 failure": the version across this
 * instance and the connected ones that could be read, or '' when it was never
 * installed on any of them.
 *
 * @param version The version.
 * @param here This instance's counts of the version, or null.
 * @param reads The connected instances' answers.
 * @return The text.
 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
 */
export function instanceTotalsText (version: string, here: VersionOutcomes | null | undefined, reads: InstanceOutcomesRead[]): string {
	const { installs, failures, instances } = instanceTotals(version, here, reads)
	if (installs === 0) {
		return failures === 0 ? '' : n('versioniq', '{count} failed install', '{count} failed installs', failures, { count: failures })
	}
	const installed = t('versioniq', '{installs} on {instances}', {
		installs: n('versioniq', 'Installed {count} time', 'Installed {count} times', installs, { count: installs }),
		instances: n('versioniq', '{count} instance', '{count} instances', instances, { count: instances }),
	})
	if (failures === 0) {
		return installed
	}

	return t('versioniq', '{installed}, {failures}', {
		installed,
		failures: n('versioniq', '{count} failure', '{count} failures', failures, { count: failures }),
	})
}

/**
 * The note naming every connected instance whose outcomes could not be read,
 * or '' when every one answered.
 *
 * @param reads The connected instances' answers.
 * @return The note.
 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
 */
export function unreadableInstancesText (reads: InstanceOutcomesRead[]): string {
	const labels = reads.filter((read) => read.outcomes === null).map((read) => read.label || read.id)
	if (labels.length === 0) {
		return ''
	}

	return t('versioniq', 'Not counted, could not be read: {instances}', { instances: labels.join(', ') })
}
