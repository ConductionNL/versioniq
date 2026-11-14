// SPDX-License-Identifier: EUPL-1.2
// The user's short day names and first day of the week, from @nextcloud/l10n,
// with English and Monday when the page has not provided them (autoupdate-schedules D6).
//
// @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
import * as l10n from '@nextcloud/l10n'

const ENGLISH_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

/** The short day names, indexed by JavaScript day (0 is Sunday). */
export function shortDayNames (): string[] {
	try {
		const names = l10n.getDayNamesShort()
		if (Array.isArray(names) && names.length === 7) {
			return names
		}
	} catch {
		// Not provided on this page; English below.
	}
	return [...ENGLISH_SHORT]
}

/** The first day of the user's week as JavaScript counts it, 0 is Sunday. */
export function firstDayOfWeek (): number {
	try {
		const day = l10n.getFirstDay()
		if (Number.isInteger(day) && day >= 0 && day <= 6) {
			return day
		}
	} catch {
		// Not provided on this page; Monday below.
	}
	return 1
}
