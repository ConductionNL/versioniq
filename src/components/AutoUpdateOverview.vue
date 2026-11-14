<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { AutoUpdateSchedule, UpdateKind } from '../utils/autoUpdateSchedule.ts'
import type { BlockedVersion, PolicyLevel } from './PolicySelector.vue'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { describeDays, kindsReachedBy, UPDATE_KINDS, weekdayOrder } from '../utils/autoUpdateSchedule.ts'
import { firstDayOfWeek, shortDayNames } from '../utils/weekdays.ts'

type PolicyRecord = { appId: string, level: PolicyLevel, blockedVersions?: BlockedVersion[] }

/**
 * Every scheduled and blocked automatic update on one view: each app with a
 * policy, the window it runs in, and each version an earlier attempt failed
 * on, with a Retry. Before this, the only place to see them was app card by
 * app card (aut-dashboard, #438).
 *
 * @spec openspec/specs/auto-update-policies/spec.md
 */
const props = defineProps<{
	policies: Record<string, PolicyRecord>
	labels: Record<string, string>
	autoUpdateEnabled: boolean
	window: string
	timeZone: string
	disabled?: boolean
	/** The stored schedule and the next run per kind, from GET /api/policies (autoupdate-schedules D5). */
	schedule?: AutoUpdateSchedule | null
	nextRuns?: Partial<Record<UpdateKind, string | null>>
}>()

const emit = defineEmits<{
	(event: 'retry', appId: string, version: string): void
}>()

const scheduled = computed(() => Object.entries(props.policies)
	.filter(([, policy]) => policy.level !== 'none')
	.map(([appId, policy]) => ({ appId: policy.appId || appId, level: policy.level }))
	.sort((a, b) => a.appId.localeCompare(b.appId)))

const blocked = computed(() => Object.entries(props.policies)
	.flatMap(([appId, policy]) => (policy.blockedVersions ?? []).map((entry) => ({ appId: policy.appId || appId, version: entry.version, at: entry.at })))
	.sort((a, b) => a.appId.localeCompare(b.appId)))

/**
 * One line per kind of update a scheduled app's policy can reach: its days and
 * next run, or "never automatically" when it has no days (autoupdate-schedules D5).
 *
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
const kindLines = computed(() => {
	const schedule = props.schedule
	if (!schedule) {
		return []
	}
	const reached = new Set(scheduled.value.flatMap((row) => kindsReachedBy(row.level)))
	const order = weekdayOrder(firstDayOfWeek())
	const names = shortDayNames()
	return UPDATE_KINDS.filter((kind) => reached.has(kind)).map((kind) => {
		const entry = schedule[kind]
		if (entry.days.length === 0) {
			return { kind, text: t('versioniq', '{kind}: never automatically', { kind: kindLabel(kind) }) }
		}
		const daysText = describeDays(entry.days, order, names, { to: t('versioniq', 'to'), everyDay: t('versioniq', 'every day') })
		const when = entry.monthly ? t('versioniq', '{days}, the first of each month', { days: daysText }) : daysText
		const next = formatRun(props.nextRuns?.[kind] ?? null)
		return {
			kind,
			text: next === ''
				? t('versioniq', '{kind}: {days}', { kind: kindLabel(kind), days: when })
				: t('versioniq', '{kind}: {days}, next run {next} ({timeZone})', { kind: kindLabel(kind), days: when, next, timeZone: props.timeZone }),
		}
	})
})

/** Whether a schedule is stored and no kind has a day, so nothing installs. */
const nothingRuns = computed(() => Boolean(props.schedule) && UPDATE_KINDS.every((kind) => props.schedule![kind].days.length === 0))

/**
 * @param kind The kind of update.
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
function kindLabel (kind: UpdateKind): string {
	return {
		patch: t('versioniq', 'Patch updates'),
		minor: t('versioniq', 'Minor updates'),
		major: t('versioniq', 'Major updates'),
	}[kind]
}

/**
 * A next run the server computed, shown in the zone the window uses; '' when there is none.
 *
 * @param iso The ISO 8601 time, or null.
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
function formatRun (iso: string | null): string {
	if (!iso) {
		return ''
	}
	const when = new Date(iso)
	if (Number.isNaN(when.getTime())) {
		return iso
	}
	try {
		return new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: props.timeZone || 'UTC' }).format(when)
	} catch {
		return when.toLocaleString()
	}
}

/**
 * @param appId The app id.
 * @spec openspec/specs/auto-update-policies/spec.md
 */
function labelFor (appId: string): string {
	return props.labels[appId] ?? appId
}

/**
 * @param level The policy level.
 * @spec openspec/specs/auto-update-policies/spec.md
 */
function levelLabel (level: PolicyLevel): string {
	return {
		none: t('versioniq', 'Off'),
		patch: t('versioniq', 'Patch'),
		minor: t('versioniq', 'Minor'),
		all: t('versioniq', 'All'),
	}[level] ?? level
}
</script>

<template>
	<section :class="$style.overview" data-testid="auto-update-overview">
		<h4>{{ t('versioniq', 'Scheduled and blocked updates') }}</h4>
		<p v-if="!autoUpdateEnabled" :class="$style.hint" data-testid="overview-disabled">
			{{ t('versioniq', 'Automatic updates are off, so nothing below runs until they are enabled.') }}
		</p>
		<p v-else :class="$style.hint">
			{{ t('versioniq', 'Runs in the window {window} ({timeZone}).', { window, timeZone }) }}
		</p>
		<p v-if="nothingRuns" :class="$style.warning" data-testid="overview-nothing-runs">
			{{ t('versioniq', 'No kind of update is scheduled, so nothing runs.') }}
		</p>
		<ul v-if="kindLines.length > 0" :class="$style.list">
			<li v-for="line in kindLines" :key="line.kind" :data-testid="`overview-kind-${line.kind}`">
				{{ line.text }}
			</li>
		</ul>
		<p v-if="scheduled.length === 0" :class="$style.hint">
			{{ t('versioniq', 'No app has an automatic update policy.') }}
		</p>
		<ul v-else :class="$style.list">
			<li v-for="row in scheduled" :key="row.appId" data-testid="overview-scheduled-row">
				<strong>{{ labelFor(row.appId) }}</strong>
				{{ t('versioniq', 'policy: {level}', { level: levelLabel(row.level) }) }}
			</li>
		</ul>
		<template v-if="blocked.length > 0">
			<p :class="$style.warning">
				{{ t('versioniq', 'Automatic updates skip these versions because an earlier attempt failed:') }}
			</p>
			<ul :class="$style.list">
				<li v-for="row in blocked" :key="`${row.appId}-${row.version}`" :class="$style.blockedRow" data-testid="overview-blocked-row">
					<strong>{{ labelFor(row.appId) }}</strong>
					<code>{{ row.version }}</code>
					<NcButton
						variant="tertiary"
						:disabled="disabled"
						@click="emit('retry', row.appId, row.version)">
						{{ t('versioniq', 'Retry {version}', { version: row.version }) }}
					</NcButton>
				</li>
			</ul>
		</template>
	</section>
</template>

<style module>
.overview {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 12px;
}

.hint {
	color: var(--color-text-maxcontrast);
}

.warning {
	color: var(--color-warning-text);
}

.list {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.blockedRow {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}
</style>
