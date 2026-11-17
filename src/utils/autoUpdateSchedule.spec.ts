// SPDX-License-Identifier: EUPL-1.2
// @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
import { describe, expect, it } from 'vitest'
import { describeDays, kindsReachedBy, scheduleFrom, weekdayOrder, withKind } from './autoUpdateSchedule.ts'

const names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const words = { to: 'to', everyDay: 'every day' }

describe('autoUpdateSchedule', () => {
	it('orders the ISO weekdays from the first day of the user\'s week', () => {
		expect(weekdayOrder(1)).toEqual([1, 2, 3, 4, 5, 6, 7])
		expect(weekdayOrder(0)).toEqual([7, 1, 2, 3, 4, 5, 6])
		expect(weekdayOrder(6)).toEqual([6, 7, 1, 2, 3, 4, 5])
		expect(weekdayOrder(9)).toEqual([1, 2, 3, 4, 5, 6, 7])
	})

	it('reads a missing or malformed schedule as every day, weekly, and keeps the valid parts', () => {
		expect(scheduleFrom(null).major).toEqual({ days: [1, 2, 3, 4, 5, 6, 7], monthly: false })
		expect(scheduleFrom({ minor: { days: [3, 3, 9, 1, 'x'], monthly: true }, major: 'weekly' })).toEqual({
			patch: { days: [1, 2, 3, 4, 5, 6, 7], monthly: false },
			minor: { days: [1, 3], monthly: true },
			major: { days: [1, 2, 3, 4, 5, 6, 7], monthly: false },
		})
	})

	it('describes runs of days, lists the rest, and names the whole week', () => {
		expect(describeDays([1, 2, 3, 4, 5], weekdayOrder(1), names, words)).toBe('Mon to Fri')
		expect(describeDays([6, 7, 1], weekdayOrder(1), names, words)).toBe('Mon, Sat, Sun')
		expect(describeDays([6, 7, 1], weekdayOrder(6), names, words)).toBe('Sat to Mon')
		expect(describeDays([2, 4], weekdayOrder(1), names, words)).toBe('Tue, Thu')
		expect(describeDays([1, 2, 3, 4, 5, 6, 7], weekdayOrder(1), names, words)).toBe('every day')
	})

	it('changes one kind or every kind without touching the input', () => {
		const start = scheduleFrom(null)
		const one = withKind(start, 'major', { days: [6, 6], monthly: true })
		const all = withKind(start, null, { days: [2], monthly: false })

		expect(one.major).toEqual({ days: [6], monthly: true })
		expect(one.patch.days).toHaveLength(7)
		expect([all.patch.days, all.minor.days, all.major.days]).toEqual([[2], [2], [2]])
		expect(start.major.days).toHaveLength(7)
	})

	it('knows which kinds each policy level reaches', () => {
		expect(kindsReachedBy('patch')).toEqual(['patch'])
		expect(kindsReachedBy('minor')).toEqual(['patch', 'minor'])
		expect(kindsReachedBy('all')).toEqual(['patch', 'minor', 'major'])
		expect(kindsReachedBy('none')).toEqual([])
	})
})
