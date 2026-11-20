// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Where an audit row came from (audit-attribution-and-forwarding D1): the admin
// page, `occ`, the nightly automatic update or the drift check. Rows written
// before origins were recorded have none and read as unknown.
//
// @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-every-audit-row-says-where-it-came-from
import { t } from '@nextcloud/l10n'

export type AuditOrigin = 'web' | 'cli' | 'job' | 'listener'

/** The `origin` filter value that selects rows without an origin. */
export const UNKNOWN_ORIGIN = 'unknown'

export type OriginFilter = AuditOrigin | typeof UNKNOWN_ORIGIN | ''

/**
 * The label of one row's origin.
 *
 * @param origin the row's origin, null on rows from before origins were recorded
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-every-audit-row-says-where-it-came-from
 */
export function originLabel (origin: string | null | undefined): string {
	switch (origin) {
	case 'web':
		return t('versioniq', 'Admin page')
	case 'cli':
		return t('versioniq', 'Command line')
	case 'job':
		return t('versioniq', 'Automatic')
	case 'listener':
		return t('versioniq', 'Drift check')
	default:
		return t('versioniq', 'Unknown')
	}
}

/**
 * The choices of the History tab's origin filter; the first lists every row.
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-every-audit-row-says-where-it-came-from
 */
export function originFilterOptions (): Array<{ id: OriginFilter, label: string }> {
	return [
		{ id: '', label: t('versioniq', 'All origins') },
		{ id: 'job', label: originLabel('job') },
		{ id: 'cli', label: originLabel('cli') },
		{ id: 'web', label: originLabel('web') },
		{ id: 'listener', label: originLabel('listener') },
		{ id: UNKNOWN_ORIGIN, label: originLabel(null) },
	]
}
