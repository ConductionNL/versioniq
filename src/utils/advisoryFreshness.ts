// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { n, t } from '@nextcloud/l10n'

export type AdvisoryFreshnessState = {
	/** True only when the fetch itself failed. */
	unavailable: boolean
	/** Unix seconds of the last completed sweep; null means none has completed. */
	checkedAt: number | null
	/** The saved advisory check interval, in hours. */
	intervalHours: number
	/** Current time in unix seconds. */
	nowSeconds: number
}

/**
 * How the advisory data should describe itself. Deliberately says something
 * in all three states rather than falling silent when there is nothing to
 * report, because silence is what made #160 invisible for so long.
 *
 * @param state The fetch outcome, the last sweep time and the saved interval.
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
export function advisoryFreshnessLabel (state: AdvisoryFreshnessState): string {
	if (state.unavailable) {
		return t('versioniq', 'Advisory status unavailable. Could not reach the server.')
	}
	if (state.checkedAt === null) {
		return n(
			'versioniq',
			'Advisories not checked yet. The background job runs every %n hour.',
			'Advisories not checked yet. The background job runs every %n hours.',
			state.intervalHours,
		)
	}

	const ageMinutes = Math.max(0, Math.round((state.nowSeconds - state.checkedAt) / 60))
	if (ageMinutes < 1) {
		return t('versioniq', 'Advisories checked just now')
	}
	if (ageMinutes < 60) {
		return t('versioniq', 'Advisories checked {minutes} min ago', { minutes: ageMinutes })
	}

	return t('versioniq', 'Advisories checked {hours} h ago', { hours: Math.round(ageMinutes / 60) })
}
