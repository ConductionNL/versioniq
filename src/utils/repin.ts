// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
// Re-pin from the drift banner: install the pinned version again.
//
// Nextcloud's own updater usually moves a pinned app UP, so re-pinning is
// usually a downgrade. The server refuses a downgrade without allowDowngrade
// (409), so re-pin goes through the same confirmation as the version list
// and sends the flag once the admin confirms (#431).

import { compareVersions } from './versionCompare.ts'

export type RepinDeps = {
	requestInstall: (appId: string, version: string, allowDowngrade: boolean) => Promise<{ metaMessage?: string }>
	confirmDowngrade: (appId: string, fromVersion: string, toVersion: string) => Promise<boolean>
}

/**
 * Re-installs the pinned version of an app, asking first when that is a
 * downgrade.
 *
 * @param appId the pinned app
 * @param pinnedVersion the version the pin holds
 * @param installedVersion the version the app runs now (the drift)
 * @param deps the install call and the downgrade confirmation
 * @spec openspec/specs/version-pinning/spec.md
 */
export async function repinApp (appId: string, pinnedVersion: string, installedVersion: string, deps: RepinDeps): Promise<{ installed: boolean, metaMessage?: string }> {
	const isDowngrade = installedVersion !== '' && compareVersions(pinnedVersion, installedVersion) < 0
	if (isDowngrade && !(await deps.confirmDowngrade(appId, installedVersion, pinnedVersion))) {
		return { installed: false }
	}

	const { metaMessage } = await deps.requestInstall(appId, pinnedVersion, isDowngrade)
	return { installed: true, metaMessage }
}
