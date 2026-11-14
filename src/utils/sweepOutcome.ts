// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { t } from '@nextcloud/l10n'

export type SweepStatus = 'ok' | 'partial' | 'failed'

/**
 * How long ago a sweep ran, as the check line says it.
 *
 * @param checkedAt Unix seconds of the sweep.
 * @param nowSeconds Current time in unix seconds.
 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
 */
export function sweepAge (checkedAt: number, nowSeconds: number): string {
	const ageMinutes = Math.max(0, Math.round((nowSeconds - checkedAt) / 60))
	if (ageMinutes < 1) {
		return t('versioniq', 'just now')
	}
	if (ageMinutes < 60) {
		return t('versioniq', '{minutes} min ago', { minutes: ageMinutes })
	}

	return t('versioniq', '{hours} h ago', { hours: Math.round(ageMinutes / 60) })
}

/**
 * The check line of a sweep that was not `ok`, or null when it was (or none
 * ran): a failed sweep MUST NOT read as checked, a partial one names the apps
 * it could not check.
 *
 * @param kind Which sweep: the advisories or the updates.
 * @param state The sweep's status, time and unchecked apps.
 * @param state.status The status.
 * @param state.errors The apps that could not be checked.
 * @param state.checkedAt Unix seconds of the sweep.
 * @param state.nowSeconds Current time in unix seconds.
 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
 */
export function sweepOutcomeLabel (
	kind: 'advisories' | 'updates',
	state: { status?: SweepStatus | null, errors?: string[], checkedAt: number | null, nowSeconds: number },
): string | null {
	if (state.checkedAt === null || !state.status || state.status === 'ok') {
		return null
	}
	const age = sweepAge(state.checkedAt, state.nowSeconds)
	if (state.status === 'failed') {
		return kind === 'advisories'
			? t('versioniq', 'Advisories could not be checked ({age})', { age })
			: t('versioniq', 'Updates could not be checked ({age})', { age })
	}
	const apps = (state.errors ?? []).join(', ')

	return kind === 'advisories'
		? t('versioniq', 'Advisories checked {age}, except for {apps}', { age, apps })
		: t('versioniq', 'Updates checked {age}, except for {apps}', { age, apps })
}
