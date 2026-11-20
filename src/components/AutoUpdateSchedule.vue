<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { AutoUpdateSchedule, KindSchedule, UpdateKind } from '../utils/autoUpdateSchedule.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { isSameForEveryKind, weekdayOrder, withKind } from '../utils/autoUpdateSchedule.ts'
import { firstDayOfWeek, shortDayNames } from '../utils/weekdays.ts'

/**
 * Which days each kind of update may install automatically (autoupdate-schedules D6):
 * the same days for every kind, or different days per kind, each with "only the
 * first of these days each month". Emits the whole schedule on every change; the
 * page saves it with the switch and the window.
 *
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
const props = withDefaults(defineProps<{
	modelValue: AutoUpdateSchedule
	disabled?: boolean
}>(), { disabled: false })

const emit = defineEmits<{
	(event: 'update:modelValue', schedule: AutoUpdateSchedule): void
}>()

const perKind = ref(!isSameForEveryKind(props.modelValue))

const dayNames = shortDayNames()
const days = computed(() => weekdayOrder(firstDayOfWeek()).map((iso) => ({ iso, name: dayNames[iso % 7] })))

const rows = computed<Array<{ kind: UpdateKind | null, label: string }>>(() => perKind.value
	? [
			{ kind: 'patch', label: t('versioniq', 'Patch updates') },
			{ kind: 'minor', label: t('versioniq', 'Minor updates') },
			{ kind: 'major', label: t('versioniq', 'Major updates') },
		]
	: [{ kind: null, label: t('versioniq', 'Every kind of update') }])

/**
 * The entry a row edits: its kind's, or the patch entry for "every kind".
 *
 * @param kind the row's kind, or null for every kind
 */
function entryOf (kind: UpdateKind | null): KindSchedule {
	return props.modelValue[kind ?? 'patch']
}

/**
 * Switch between the same days for every kind and days per kind; going back to
 * the same days gives every kind the patch days.
 *
 * @param value whether each kind gets its own days
 */
function setPerKind (value: boolean): void {
	perKind.value = value
	if (!value && !isSameForEveryKind(props.modelValue)) {
		emit('update:modelValue', withKind(props.modelValue, null, entryOf('patch')))
	}
}

/**
 * Tick or untick one day of one row.
 *
 * @param kind the row's kind, or null for every kind
 * @param day the ISO weekday
 * @param on whether the day is now ticked
 */
function toggleDay (kind: UpdateKind | null, day: number, on: boolean): void {
	const entry = entryOf(kind)
	const next = on ? [...entry.days, day] : entry.days.filter((d) => d !== day)
	emit('update:modelValue', withKind(props.modelValue, kind, { days: next, monthly: entry.monthly }))
}

/**
 * Tick or untick "only the first of these days each month" for one row.
 *
 * @param kind the row's kind, or null for every kind
 * @param monthly whether only the first such day each month counts
 */
function setMonthly (kind: UpdateKind | null, monthly: boolean): void {
	emit('update:modelValue', withKind(props.modelValue, kind, { days: entryOf(kind).days, monthly }))
}

const groupName = `auto-update-schedule-mode-${Math.random().toString(36).slice(2)}`
</script>

<template>
	<fieldset :class="$style.schedule" data-testid="auto-update-schedule" :disabled="disabled">
		<legend>{{ t('versioniq', 'Days on which updates install') }}</legend>
		<label :class="$style.mode">
			<input
				type="radio"
				:name="groupName"
				data-testid="schedule-mode-same"
				:checked="!perKind"
				@change="setPerKind(false)">
			{{ t('versioniq', 'Same days for every update') }}
		</label>
		<label :class="$style.mode">
			<input
				type="radio"
				:name="groupName"
				data-testid="schedule-mode-per-kind"
				:checked="perKind"
				@change="setPerKind(true)">
			{{ t('versioniq', 'Different days per kind of update') }}
		</label>
		<div v-for="row in rows"
			:key="row.kind ?? 'all'"
			:class="$style.row"
			:data-testid="`schedule-row-${row.kind ?? 'all'}`">
			<span :class="$style.rowLabel">{{ row.label }}</span>
			<span :class="$style.days">
				<label v-for="day in days" :key="day.iso" :class="$style.day">
					<input
						type="checkbox"
						:data-testid="`schedule-day-${row.kind ?? 'all'}-${day.iso}`"
						:checked="entryOf(row.kind).days.includes(day.iso)"
						@change="toggleDay(row.kind, day.iso, ($event.target as HTMLInputElement).checked)">
					{{ day.name }}
				</label>
			</span>
			<label :class="$style.monthly">
				<input
					type="checkbox"
					:data-testid="`schedule-monthly-${row.kind ?? 'all'}`"
					:checked="entryOf(row.kind).monthly"
					@change="setMonthly(row.kind, ($event.target as HTMLInputElement).checked)">
				{{ t('versioniq', 'Only the first of these days each month') }}
			</label>
			<span v-if="entryOf(row.kind).days.length === 0" :class="$style.never" :data-testid="`schedule-never-${row.kind ?? 'all'}`">
				{{ t('versioniq', 'No day is ticked, so this never installs automatically.') }}
			</span>
		</div>
	</fieldset>
</template>

<style module>
.schedule {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 8px 0;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.mode,
.day,
.monthly {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-top: 4px;
}

.rowLabel {
	font-weight: bold;
}

.days {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.never {
	color: var(--color-warning-text);
}
</style>
