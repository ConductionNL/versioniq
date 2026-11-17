// SPDX-License-Identifier: EUPL-1.2
// The automatic update schedule (autoupdate-schedules D2): per kind of update,
// the ISO weekdays it may run on (1 is Monday, 7 is Sunday) and whether only the
// first of those days each month counts. The server validates and stores it and
// computes the next runs; this module only shapes and describes it.
//
// @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md

export const UPDATE_KINDS = ['patch', 'minor', 'major'] as const

export type UpdateKind = typeof UPDATE_KINDS[number]

export type KindSchedule = { days: number[], monthly: boolean }

export type AutoUpdateSchedule = Record<UpdateKind, KindSchedule>

const KINDS_BY_LEVEL: Record<string, readonly UpdateKind[]> = {
	patch: ['patch'],
	minor: ['patch', 'minor'],
	all: UPDATE_KINDS,
}

export const EVERY_DAY = [1, 2, 3, 4, 5, 6, 7]

/**
 * Every kind on every day, weekly: what the server reads when nothing is stored.
 *
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
export function defaultSchedule (): AutoUpdateSchedule {
	return {
		patch: { days: [...EVERY_DAY], monthly: false },
		minor: { days: [...EVERY_DAY], monthly: false },
		major: { days: [...EVERY_DAY], monthly: false },
	}
}

/**
 * A schedule as the server sent it, with anything missing or malformed read as the default.
 *
 * @param value the `autoUpdateSchedule` of GET /api/policies
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
export function scheduleFrom (value: unknown): AutoUpdateSchedule {
	const schedule = defaultSchedule()
	if (value === null || typeof value !== 'object') {
		return schedule
	}
	for (const kind of UPDATE_KINDS) {
		readEntry(schedule[kind], (value as Record<string, unknown>)[kind])
	}
	return schedule
}

/**
 * Copy what a sent entry holds onto one kind's entry; a malformed entry changes nothing.
 *
 * @param target the kind's entry, holding the default
 * @param entry what the server sent for the kind
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
function readEntry (target: KindSchedule, entry: unknown): void {
	if (entry === null || typeof entry !== 'object') {
		return
	}
	const { days, monthly } = entry as { days?: unknown, monthly?: unknown }
	if (Array.isArray(days)) {
		target.days = normalizedDays(days)
	}
	target.monthly = monthly === true
}

/**
 * Distinct weekdays 1 to 7, ascending.
 *
 * @param days the days to keep
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
export function normalizedDays (days: unknown[]): number[] {
	return [...new Set(days.filter((day): day is number => Number.isInteger(day) && (day as number) >= 1 && (day as number) <= 7))].sort((a, b) => a - b)
}

/**
 * Whether every kind has the same days and the same monthly choice.
 *
 * @param schedule the schedule
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
export function isSameForEveryKind (schedule: AutoUpdateSchedule): boolean {
	const first = JSON.stringify(schedule.patch)
	return UPDATE_KINDS.every((kind) => JSON.stringify(schedule[kind]) === first)
}

/**
 * The schedule with one kind's entry replaced, or every kind's when `kind` is null.
 *
 * @param schedule the schedule
 * @param kind the kind to change, or null for every kind
 * @param entry its new days and monthly choice
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
export function withKind (schedule: AutoUpdateSchedule, kind: UpdateKind | null, entry: KindSchedule): AutoUpdateSchedule {
	const next: AutoUpdateSchedule = {
		patch: { ...schedule.patch, days: [...schedule.patch.days] },
		minor: { ...schedule.minor, days: [...schedule.minor.days] },
		major: { ...schedule.major, days: [...schedule.major.days] },
	}
	for (const target of kind === null ? UPDATE_KINDS : [kind]) {
		next[target] = { days: normalizedDays(entry.days), monthly: entry.monthly }
	}
	return next
}

/**
 * The ISO weekdays in the order a week starts for the user.
 *
 * @param firstDay the first day of the week as JavaScript counts it, 0 is Sunday
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
export function weekdayOrder (firstDay: number): number[] {
	const start = Number.isInteger(firstDay) && firstDay >= 0 && firstDay <= 6 ? firstDay : 1
	return Array.from({ length: 7 }, (_, offset) => {
		const jsDay = (start + offset) % 7
		return jsDay === 0 ? 7 : jsDay
	})
}

/**
 * The JavaScript day index (0 is Sunday) of an ISO weekday, for the day-name lists.
 *
 * @param isoDay 1 to 7
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
export function jsDayOf (isoDay: number): number {
	return isoDay % 7
}

/**
 * The kinds of update an app's policy can reach: patch for patch, patch and
 * minor for minor, all three for all.
 *
 * @param level the app's policy level
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
export function kindsReachedBy (level: string): UpdateKind[] {
	return Object.hasOwn(KINDS_BY_LEVEL, level) ? [...(KINDS_BY_LEVEL[level] ?? [])] : []
}

/**
 * The days as text in week order: a run of three or more consecutive days reads
 * "Mon to Fri", other days are listed, and all seven read as `everyDay`.
 *
 * @param days the ISO weekdays
 * @param order the ISO weekdays in the user's week order
 * @param names the short day names, indexed by JavaScript day (0 is Sunday)
 * @param words the joining words: `to` for a run, `everyDay` for all seven
 * @param words.to the word between the ends of a run
 * @param words.everyDay the text for all seven days
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
export function describeDays (days: number[], order: number[], names: string[], words: { to: string, everyDay: string }): string {
	const chosen = new Set(normalizedDays(days))
	if (chosen.size === 7) {
		return words.everyDay
	}
	const parts: string[] = []
	let run: number[] = []
	const flush = (): void => {
		if (run.length >= 3) {
			parts.push(`${names[jsDayOf(run[0])]} ${words.to} ${names[jsDayOf(run[run.length - 1])]}`)
		} else {
			parts.push(...run.map((day) => names[jsDayOf(day)]))
		}
		run = []
	}
	for (const day of order) {
		if (chosen.has(day)) {
			run.push(day)
		} else if (run.length > 0) {
			flush()
		}
	}
	if (run.length > 0) {
		flush()
	}
	return parts.join(', ')
}
