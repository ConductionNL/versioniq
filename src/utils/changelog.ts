// Pure helpers for the aggregate changelog range panel — "Aggregate range
// changelog on target selection" (add-changelog-visibility).
//
// @spec openspec/specs/changelog-visibility/spec.md
import { compareVersions } from './versionCompare.ts'

export type ChangelogSource = {
	version: string
	changelog?: string | null
}

export type ChangelogRangeEntry = {
	version: string
	changelog: string | null
}

export type ChangelogEndpoints = {
	installed: string
	target: string
}

type VersionWindow = {
	low: string
	high: string
	ascending: boolean
}

/**
 * The versions a range spans: above `low`, up to and including `high`, listed
 * ascending for an upgrade. Null when an endpoint is missing or both are equal.
 *
 * @param endpoints The installed and the target version.
 * @param endpoints.installed The installed version.
 * @param endpoints.target The target version.
 */
function versionWindow({ installed, target }: ChangelogEndpoints): VersionWindow | null {
	if (!installed || !target || installed === target) {
		return null
	}

	const ascending = compareVersions(target, installed) > 0
	return ascending
		? { low: installed, high: target, ascending }
		: { low: target, high: installed, ascending }
}

/**
 * Whether a version lies above the window's low end and at or below its high end.
 *
 * @param bounds The version window.
 * @param version The version to test.
 */
function inWindow(bounds: VersionWindow, version: string): boolean {
	return compareVersions(version, bounds.low) > 0 && compareVersions(version, bounds.high) <= 0
}

/**
 * The sort order of the range: ascending, or newest first.
 *
 * @param ascending Whether the range lists oldest first.
 */
function rangeOrder(ascending: boolean): (a: ChangelogRangeEntry, b: ChangelogRangeEntry) => number {
	if (ascending) {
		return (a, b) => compareVersions(a.version, b.version)
	}
	return (a, b) => compareVersions(b.version, a.version)
}

/**
 * Builds the ordered list of intermediate releases between the installed
 * version and the selected target, for the aggregate "changes between
 * installed → target" panel.
 *
 * Both directions exclude the numerically-lower endpoint of the pair and
 * include the higher one; only the display order differs:
 *   - Upgrade (target newer): ascending, from just-above-installed towards
 *     the target — "installed 2.3.0 → target 2.5.0" lists 2.4.0, 2.5.0.
 *   - Downgrade (target older): descending, newest first — the releases
 *     being rolled back — "installed 2.5.0 → target 2.3.0" lists 2.5.0,
 *     2.4.0 (2.3.0 itself, the target being returned to, is not listed).
 *
 * Returns an empty list when either endpoint is missing or they are equal
 * (no range to show). Reuses the already-fetched `versions` list — this
 * function issues zero requests.
 *
 * @param endpoints The installed and the target version.
 * @param versions The fetched releases.
 */
export function buildChangelogRange(endpoints: ChangelogEndpoints, versions: ChangelogSource[]): ChangelogRangeEntry[] {
	const bounds = versionWindow(endpoints)
	if (bounds === null) {
		return []
	}

	return versions
		.filter((entry) => inWindow(bounds, entry.version))
		.map((entry) => ({ version: entry.version, changelog: entry.changelog ?? null }))
		.sort(rangeOrder(bounds.ascending))
}
