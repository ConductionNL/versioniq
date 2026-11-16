// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { t } from '@nextcloud/l10n'

export type SweepStatus = 'ok' | 'partial' | 'failed'

/**
 * How long ago a sweep ran, as the check line says it.
 *
 * @param checkedAt Unix seconds of the sweep.
 * @param nowSeconds Current time in unix seconds.
 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
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
 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
 */
export function sweepOutcomeLabel (
	kind: 'advisories' | 'updates',
	state: { status?: SweepStatus | null, errors?: string[], checkedAt: number | null, nowSeconds: number },
): string | null {
	if (state.checkedAt === null || !hasIssue(state.status)) {
		return null
	}
	const age = sweepAge(state.checkedAt, state.nowSeconds)

	return state.status === 'failed'
		? failedLabel(kind, age)
		: partialLabel(kind, age, (state.errors ?? []).join(', '))
}

/**
 * Whether a sweep status says not every source was read.
 *
 * @param status The sweep's status, if any.
 */
function hasIssue (status: SweepStatus | null | undefined): boolean {
	return status === 'failed' || status === 'partial'
}

/**
 * The check line of a sweep that failed as a whole.
 *
 * @param kind Which sweep: the advisories or the updates.
 * @param age How long ago it ran.
 */
function failedLabel (kind: 'advisories' | 'updates', age: string): string {
	return kind === 'advisories'
		? t('versioniq', 'Advisories could not be checked ({age})', { age })
		: t('versioniq', 'Updates could not be checked ({age})', { age })
}

/**
 * The check line of a sweep that could not check some apps.
 *
 * @param kind Which sweep: the advisories or the updates.
 * @param age How long ago it ran.
 * @param apps The apps it could not check, comma-separated.
 */
function partialLabel (kind: 'advisories' | 'updates', age: string, apps: string): string {
	return kind === 'advisories'
		? t('versioniq', 'Advisories checked {age}, except for {apps}', { age, apps })
		: t('versioniq', 'Updates checked {age}, except for {apps}', { age, apps })
}

/**
 * The sweep status and unchecked apps of a stored-sweep answer, with the
 * defaults of an answer that predates them.
 *
 * @param payload The answer of /api/advisories or /api/updates.
 * @param payload.status The sweep's status.
 * @param payload.errors The apps the sweep could not check.
 */
export function sweepStateOf (payload: { status?: SweepStatus | null, errors?: string[] }): { status: SweepStatus | null, errors: string[] } {
	return { status: payload.status ?? null, errors: payload.errors ?? [] }
}
