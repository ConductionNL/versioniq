// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// How the History tab names an audit entry's operation
// (sources-trust-audit-and-offline-feed 1.2). The trusted-source entries
// carry the pattern as their message; an unknown operation shows as stored.
// @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/audit-trail/spec.md
import { t } from '@nextcloud/l10n'

type OperationEntry = {
	operation: string
	message: string | null
}

/**
 * A trusted-source pattern as people read it: `codeberg.org/zuiddrecht/*`
 * shows as `codeberg.org/zuiddrecht`.
 *
 * @param pattern the stored pattern
 */
export function displayPattern (pattern: string): string {
	return pattern.trim().replace(/\/\*$/, '').replace(/\/$/, '')
}

/**
 * The text the History tab shows for an entry's operation.
 *
 * @param entry the audit entry
 */
export function operationLabel (entry: OperationEntry): string {
	const pattern = displayPattern(entry.message ?? '')
	switch (entry.operation) {
	case 'trust_source':
		return t('versioniq', 'Trusted source added: {pattern}', { pattern })
	case 'untrust_source':
		return t('versioniq', 'Trusted source removed: {pattern}', { pattern })
	case 'unbind_source':
		return t('versioniq', 'Source binding cleared')
	default:
		return entry.operation
	}
}

/**
 * Whether the entry's message is already part of its operation label, so
 * the Status column does not repeat it.
 *
 * @param entry the audit entry
 */
export function labelCarriesMessage (entry: OperationEntry): boolean {
	return entry.operation === 'trust_source' || entry.operation === 'untrust_source'
}
