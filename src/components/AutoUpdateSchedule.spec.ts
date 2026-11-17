// SPDX-License-Identifier: EUPL-1.2
// Covers the schedule form of autoupdate-schedules (task 3.2): the same days for
// every kind, days per kind, and "only the first of these days each month".
// @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AutoUpdateSchedule from './AutoUpdateSchedule.vue'
import { defaultSchedule } from '../utils/autoUpdateSchedule.ts'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, vars: Record<string, unknown> = {}) =>
		text.replace(/\{(\w+)\}/g, (_match: string, key: string) => String(vars[key] ?? '')),
	getDayNamesShort: () => ['zo', 'ma', 'di', 'wo', 'do', 'vr', 'za'],
	getFirstDay: () => 1,
}))

type Schedule = ReturnType<typeof defaultSchedule>

function lastEmitted (wrapper: ReturnType<typeof mount>): Schedule {
	const events = wrapper.emitted('update:modelValue') as Schedule[][]
	return events[events.length - 1][0]
}

describe('AutoUpdateSchedule', () => {
	it('starts in the same-days mode with one row of localized days from the first day of the week', () => {
		const wrapper = mount(AutoUpdateSchedule, { props: { modelValue: defaultSchedule() } })

		expect((wrapper.find('[data-testid="schedule-mode-same"]').element as HTMLInputElement).checked).toBe(true)
		expect(wrapper.findAll('[data-testid^="schedule-row-"]').map((row) => row.attributes('data-testid'))).toEqual(['schedule-row-all'])
		expect(wrapper.find('[data-testid="schedule-row-all"]').text()).toMatch(/ma.*di.*wo.*do.*vr.*za.*zo/s)
	})

	it('unticking a day in the same-days mode takes it off every kind', async () => {
		const wrapper = mount(AutoUpdateSchedule, { props: { modelValue: defaultSchedule() } })

		await wrapper.find('[data-testid="schedule-day-all-6"]').setValue(false)
		await wrapper.setProps({ modelValue: lastEmitted(wrapper) })
		await wrapper.find('[data-testid="schedule-day-all-7"]').setValue(false)

		expect(JSON.parse(JSON.stringify(lastEmitted(wrapper)))).toEqual({
			patch: { days: [1, 2, 3, 4, 5], monthly: false },
			minor: { days: [1, 2, 3, 4, 5], monthly: false },
			major: { days: [1, 2, 3, 4, 5], monthly: false },
		})
	})

	it('gives each kind its own days in the per-kind mode', async () => {
		const wrapper = mount(AutoUpdateSchedule, { props: { modelValue: defaultSchedule() } })

		await wrapper.find('[data-testid="schedule-mode-per-kind"]').setValue(true)
		expect(wrapper.findAll('[data-testid^="schedule-row-"]').map((row) => row.attributes('data-testid'))).toEqual(['schedule-row-patch', 'schedule-row-minor', 'schedule-row-major'])

		await wrapper.find('[data-testid="schedule-day-major-1"]').setValue(false)

		const emitted = lastEmitted(wrapper)
		expect(emitted.major.days).toEqual([2, 3, 4, 5, 6, 7])
		expect(emitted.patch.days).toEqual([1, 2, 3, 4, 5, 6, 7])
		expect(emitted.minor.days).toEqual([1, 2, 3, 4, 5, 6, 7])
	})

	it('opens in the per-kind mode for a stored schedule that differs per kind, and the monthly box sets that kind only', async () => {
		const stored = { patch: { days: [1, 2, 3, 4, 5, 6, 7], monthly: false }, minor: { days: [2], monthly: false }, major: { days: [6], monthly: false } }
		const wrapper = mount(AutoUpdateSchedule, { props: { modelValue: stored } })

		expect((wrapper.find('[data-testid="schedule-mode-per-kind"]').element as HTMLInputElement).checked).toBe(true)
		await wrapper.find('[data-testid="schedule-monthly-major"]').setValue(true)

		expect(JSON.parse(JSON.stringify(lastEmitted(wrapper)))).toEqual({
			patch: { days: [1, 2, 3, 4, 5, 6, 7], monthly: false },
			minor: { days: [2], monthly: false },
			major: { days: [6], monthly: true },
		})
	})

	it('going back to the same days gives every kind the patch days', async () => {
		const stored = { patch: { days: [1, 3], monthly: false }, minor: { days: [2], monthly: false }, major: { days: [], monthly: true } }
		const wrapper = mount(AutoUpdateSchedule, { props: { modelValue: stored } })

		await wrapper.find('[data-testid="schedule-mode-same"]').setValue(true)

		expect(JSON.parse(JSON.stringify(lastEmitted(wrapper)))).toEqual({
			patch: { days: [1, 3], monthly: false },
			minor: { days: [1, 3], monthly: false },
			major: { days: [1, 3], monthly: false },
		})
	})

	it('says a kind with no day never installs automatically', () => {
		const stored = { patch: { days: [1], monthly: false }, minor: { days: [1], monthly: false }, major: { days: [], monthly: false } }
		const wrapper = mount(AutoUpdateSchedule, { props: { modelValue: stored } })

		expect(wrapper.find('[data-testid="schedule-never-major"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="schedule-never-patch"]').exists()).toBe(false)
	})
})
