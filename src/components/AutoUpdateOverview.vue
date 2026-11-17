<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { AutoUpdateSchedule, UpdateKind } from '../utils/autoUpdateSchedule.ts'
import type {PendingState} from '../utils/pendingAutoUpdates.ts';
import type { PendingUpdate } from '../utils/pendingUpdates.ts'
import type { BlockedVersion, PolicyLevel } from './PolicySelector.vue'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { describeDays, kindsReachedBy, UPDATE_KINDS, weekdayOrder } from '../utils/autoUpdateSchedule.ts'
import { pendingAutoUpdates } from '../utils/pendingAutoUpdates.ts'
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
	securityHolds?: Record<string, string>
	advisoriesFresh?: boolean
	manualKinds?: string[]
	disabled?: boolean
	/** The stored schedule and the next run per kind, from GET /api/policies (autoupdate-schedules D5). */
	schedule?: AutoUpdateSchedule | null
	nextRuns?: Partial<Record<UpdateKind, string | null>>
	updates?: Record<string, PendingUpdate>
	updatesCheckedLabel?: string
}>()

const emit = defineEmits<{
	(event: 'retry', appId: string, version: string): void
}>()

const scheduled = computed(() => Object.entries(props.policies)
	.filter(([, policy]) => policy.level !== 'none')
	.map(([appId, policy]) => ({ appId: policy.appId || appId, level: policy.level }))
	.sort((a, b) => a.appId.localeCompare(b.appId)))

// The newer version each app with a policy can go to now, from the stored
// availability sweep: no source is asked when the page opens.
const pending = computed(() => pendingAutoUpdates(props.policies, props.updates ?? {}))

/**
 * @param state The pending state.
 * @spec openspec/specs/auto-update-policies/spec.md#requirement-the-overview-lists-the-updates-that-are-pending-now
 */
function stateLabel (state: PendingState): string {
	return {
		scheduled: t('versioniq', 'Scheduled for the next window'),
		pinned: t('versioniq', 'Held by the pin'),
		outsidePolicy: t('versioniq', 'Not covered by the policy'),
		failed: t('versioniq', 'Failed earlier'),
	}[state]
}

const blocked = computed(() => Object.entries(props.policies)
	.flatMap(([appId, policy]) => (policy.blockedVersions ?? []).map((entry) => ({ appId: policy.appId || appId, version: entry.version, at: entry.at })))
	.sort((a, b) => a.appId.localeCompare(b.appId)))

// Why the last run installed no security fix for an app (autoupdate-security-first).
const held = computed(() => Object.entries(props.securityHolds ?? {})
	.map(([appId, reason]) => ({ appId, reason }))
	.sort((a, b) => a.appId.localeCompare(b.appId)))

// The stale-snapshot line shows while any app is on `security`.
const showStale = computed(() => props.advisoriesFresh === false
	&& Object.values(props.policies).some((policy) => policy.level === 'security'))

const byHand = computed(() => (props.manualKinds ?? []).map((kind) => kindByHand(kind)))

/**
 * @param reason The reason the job recorded.
 * @param app The app's name.
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function holdText (reason: string, app: string): string {
	return {
		no_fix: t('versioniq', '{app} is affected by an advisory that names no fix to install.', { app }),
		needs_major: t('versioniq', 'The security fix for {app} needs a major update, so it was not installed.', { app }),
		not_in_source: t('versioniq', 'The security fix for {app} is not available from its source yet.', { app }),
		stale: t('versioniq', 'The security fix for {app} waits until advisories are checked again.', { app }),
		manual: t('versioniq', 'The patch policy sets the security fix for {app} to by hand.', { app }),
	}[reason] ?? `${app}: ${reason}`
}

/**
 * @param kind The regular kind of update.
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
function kindByHand (kind: string): string {
	return {
		patch: t('versioniq', 'Patch updates are set to by hand in the patch policy, so no app gets them automatically.'),
		minor: t('versioniq', 'Minor updates are set to by hand in the patch policy, so no app gets them automatically.'),
		major: t('versioniq', 'Major updates are set to by hand in the patch policy, so no app gets them automatically.'),
	}[kind] ?? kind
}

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
		security: t('versioniq', 'Security fixes only'),
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
		<p v-if="showStale" :class="$style.warning" data-testid="overview-stale">
			{{ t('versioniq', 'Advisories were not checked recently, so security fixes wait.') }}
		</p>
		<ul v-if="held.length > 0" :class="$style.list">
			<li v-for="row in held" :key="row.appId" data-testid="overview-held-row">
				{{ holdText(row.reason, labelFor(row.appId)) }}
			</li>
		</ul>
		<p v-for="line in byHand" :key="line" :class="$style.hint" data-testid="overview-by-hand">
			{{ line }}
		</p>
		<h5 :class="$style.subheading">
			{{ t('versioniq', 'Pending updates') }}
		</h5>
		<p v-if="updatesCheckedLabel" :class="$style.hint" data-testid="overview-pending-checked">
			{{ updatesCheckedLabel }}
		</p>
		<p v-if="pending.length === 0" :class="$style.hint" data-testid="overview-pending-none">
			{{ t('versioniq', 'No app with a policy has a newer version available.') }}
		</p>
		<ul v-else :class="$style.list">
			<li
				v-for="row in pending"
				:key="row.appId"
				:class="$style.blockedRow"
				data-testid="overview-pending-row">
				<strong>{{ labelFor(row.appId) }}</strong>
				<code>{{ row.version }}</code>
				<span :data-state="row.state">{{ stateLabel(row.state) }}</span>
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

.subheading {
	margin: 8px 0 0;
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
