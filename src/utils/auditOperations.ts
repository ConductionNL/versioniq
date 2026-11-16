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
 *
 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/audit-trail/spec.md
 */
export function displayPattern (pattern: string): string {
	return pattern.trim().replace(/\/\*$/, '').replace(/\/$/, '')
}

// The label of each operation the History tab names itself, by operation.
// An operation not listed here shows as its own name.
const OPERATION_LABELS = new Map<string, (pattern: string) => string>([
	['trust_source', (pattern) => t('versioniq', 'Trusted source added: {pattern}', { pattern })],
	['untrust_source', (pattern) => t('versioniq', 'Trusted source removed: {pattern}', { pattern })],
	['unbind_source', () => t('versioniq', 'Source binding cleared')],
])

/**
 * The text the History tab shows for an entry's operation.
 *
 * @param entry the audit entry
 *
 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/audit-trail/spec.md
 */
export function operationLabel (entry: OperationEntry): string {
	const label = OPERATION_LABELS.get(entry.operation)
	return label ? label(displayPattern(entry.message ?? '')) : entry.operation
}

/**
 * Whether the entry's message is already part of its operation label, so
 * the Status column does not repeat it.
 *
 * @param entry the audit entry
 *
 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/audit-trail/spec.md
 */
export function labelCarriesMessage (entry: OperationEntry): boolean {
	return entry.operation === 'trust_source' || entry.operation === 'untrust_source'
}
