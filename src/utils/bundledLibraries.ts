// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The bundled-library snapshot the daily OSV check stores per app, as
// `GET /api/advisories` returns it under `libraries`.
//
// @spec openspec/changes/advisories-bundled-libraries

import { t } from '@nextcloud/l10n'

export type LibraryFinding = {
	package: string
	version: string
	id: string
	cveIds: string[]
	severity: string
	summary: string
	fixedIn: string | null
	url: string
	malicious: boolean
}

export type LibraryCheck = {
	manifest: string | null
	checked: number
	skipped: number
	error: string | null
	findings: LibraryFinding[]
}

/** The anchor of the Bundled libraries block on the Advisories tab. */
export const BUNDLED_LIBRARIES_ANCHOR = 'section-bundled-libraries'

/**
 * Whether an app's last library check found anything, for the card badge.
 *
 * @param check The app's entry in the snapshot, if any.
 * @spec openspec/changes/advisories-bundled-libraries
 */
export function hasLibraryFindings (check: LibraryCheck | null | undefined): boolean {
	return Array.isArray(check?.findings) && check.findings.length > 0
}

/**
 * The apps to list, sorted with the ones carrying findings first, then by id.
 *
 * @param snapshot The stored snapshot per app id.
 * @spec openspec/changes/advisories-bundled-libraries
 */
export function libraryRows (snapshot: Record<string, LibraryCheck>): Array<{ appId: string, check: LibraryCheck }> {
	return Object.entries(snapshot)
		.map(([appId, check]) => ({ appId, check }))
		.sort((a, b) => Number(hasLibraryFindings(b.check)) - Number(hasLibraryFindings(a.check)) || a.appId.localeCompare(b.appId))
}

/**
 * How old the last library check is, or nothing when none has completed
 * (the block then says the libraries have not been checked).
 *
 * @param checkedAt Unix seconds of the last completed check, or null.
 * @param nowSeconds Current time in unix seconds.
 * @spec openspec/changes/advisories-bundled-libraries
 */
export function librariesFreshnessLabel (checkedAt: number | null, nowSeconds: number): string {
	if (checkedAt === null) {
		return ''
	}
	const ageMinutes = Math.max(0, Math.round((nowSeconds - checkedAt) / 60))
	if (ageMinutes < 1) {
		return t('versioniq', 'Libraries checked just now')
	}
	if (ageMinutes < 60) {
		return t('versioniq', 'Libraries checked {minutes} min ago', { minutes: ageMinutes })
	}

	return t('versioniq', 'Libraries checked {hours} h ago', { hours: Math.round(ageMinutes / 60) })
}
