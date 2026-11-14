// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The local copy of the advisory feed on the settings page
// (sources-trust-audit-and-offline-feed 3.3): the import, and the line that
// names the copy's date and warns when it is older than 30 days.
// @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
import { t } from '@nextcloud/l10n'
import { ocsUpload } from '../ocs.ts'
import { errorMessage } from './errorMessage.ts'

const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/advisory/feed-copy'

export type FeedCopyState = { text: string, stale: boolean }

/**
 * The line about the imported copy: its date, and whether it is older than
 * $maxAgeDays at $now. Null while no copy was imported.
 *
 * @param copiedAt when the copy was imported, a Unix time, or null
 * @param maxAgeDays the age past which the copy is stale
 * @param now the current time in milliseconds
 * @param locale the locale to write the date in; the browser's when omitted
 */
export function feedCopyState (copiedAt: number | null, maxAgeDays: number, now: number, locale?: string): FeedCopyState | null {
	if (copiedAt === null || copiedAt === undefined) {
		return null
	}
	const date = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(new Date(copiedAt * 1000))
	const stale = now - copiedAt * 1000 > maxAgeDays * 86400 * 1000
	return {
		text: t('versioniq', 'Local copy of {date}', { date }),
		stale,
	}
}

/**
 * Imports a feed file; answers the import time, or the reason it was refused.
 *
 * @param file the chosen feed file
 */
export async function importFeedCopy (file: Blob): Promise<{ copiedAt: number } | { error: string }> {
	try {
		const { payload, error } = await ocsUpload<{ copiedAt?: number }>(ENDPOINT, 'file', file)
		if (error) {
			return { error }
		}
		return typeof payload.copiedAt === 'number'
			? { copiedAt: payload.copiedAt }
			: { error: t('versioniq', 'The advisory feed copy could not be imported.') }
	} catch (caught) {
		return { error: errorMessage(caught, t('versioniq', 'The advisory feed copy could not be imported.')) }
	}
}
