// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { getCanonicalLocale, t } from '@nextcloud/l10n'

/** What POST /api/apps/{appId}/restore answered (safety-rollback-backup-and-preflight D4). */
export type RestorePayload = {
	appId?: unknown
	version?: unknown
	backupCreatedAt?: unknown
	message?: unknown
	code?: unknown
}

/**
 * The line the result card shows after "Restore installation": what was put
 * back, that there is no backup and a rollback is the way back, or why the
 * restore failed.
 *
 * @param status The HTTP status of the answer.
 * @param payload The answer's data.
 * @spec openspec/specs/migration-safety/spec.md
 */
export function restoreOutcomeText (status: number, payload: RestorePayload): string {
	if (status === 200 && typeof payload.appId === 'string' && typeof payload.version === 'string') {
		const created = typeof payload.backupCreatedAt === 'string' ? new Date(payload.backupCreatedAt) : null
		const date = created === null || Number.isNaN(created.getTime())
			? ''
			: new Intl.DateTimeFormat(getCanonicalLocale(), { dateStyle: 'medium', timeZone: 'UTC' }).format(created)

		return t('versioniq', 'Restored {appId} {version} with its tables from the backup of {date}.', { appId: payload.appId, version: payload.version, date })
	}
	if (payload.code === 'no_backup') {
		return t('versioniq', 'There is no backup to restore. Roll back to an earlier version instead.')
	}

	return typeof payload.message === 'string' && payload.message !== ''
		? payload.message
		: t('versioniq', 'Could not restore the installation.')
}
