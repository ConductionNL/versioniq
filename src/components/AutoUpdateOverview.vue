<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type {PendingState} from '../utils/pendingAutoUpdates.ts';
import type { PendingUpdate } from '../utils/pendingUpdates.ts'
import type { BlockedVersion, PolicyLevel } from './PolicySelector.vue'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { pendingAutoUpdates } from '../utils/pendingAutoUpdates.ts'

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
	updates?: Record<string, PendingUpdate>
	updatesCheckedLabel?: string
	groups?: Array<{ id?: string, name: string, apps: string[] }>
	waiting?: Record<string, string>
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

// Each update group with what holds it, the first member in its order that
// is pinned or has a failed version (autoupdate-run-batching D3).
const groupRows = computed(() => (props.groups ?? []).map((group) => ({
	name: group.name,
	apps: group.apps,
	status: groupStatus(group.apps),
})))

// Units a limit held back, oldest first (autoupdate-run-batching D5).
const waitingRows = computed(() => Object.entries(props.waiting ?? {})
	.map(([unit, since]) => ({ unit, since }))
	.sort((a, b) => a.since.localeCompare(b.since)))

/**
 * @param apps The group's apps, in install order.
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function groupStatus (apps: string[]): { held: boolean, text: string } {
	const pinned = apps.find((appId) => props.updates?.[appId]?.pinned === true)
	if (pinned !== undefined) {
		return { held: true, text: t('versioniq', 'Held by the pin on {app}', { app: labelFor(pinned) }) }
	}
	const failed = apps.find((appId) => (props.policies[appId]?.blockedVersions ?? []).length > 0)
	if (failed !== undefined) {
		return { held: true, text: t('versioniq', 'Blocked until the failed update of {app} is retried', { app: labelFor(failed) }) }
	}
	return { held: false, text: t('versioniq', 'Updates together, in this order') }
}

/**
 * @param unit The waiting-list key: `app:<id>` or `group:<name>`.
 * @param since When the limit first held it back (ISO-8601).
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function waitingText (unit: string, since: string): string {
	const date = since.slice(0, 10)
	if (unit.startsWith('group:')) {
		return t('versioniq', 'Update group {name}: waiting for the limit since {date}', { name: unit.slice(6), date })
	}
	return t('versioniq', '{app}: waiting for the limit since {date}', { app: labelFor(unit.replace(/^app:/, '')), date })
}

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
		<template v-if="groupRows.length > 0">
			<h5 :class="$style.subheading">
				{{ t('versioniq', 'Update groups') }}
			</h5>
			<ul :class="$style.list">
				<li v-for="group in groupRows" :key="group.name" :class="$style.blockedRow" data-testid="overview-group-row">
					<strong>{{ group.name }}</strong>
					<span>{{ group.apps.map(labelFor).join(' → ') }}</span>
					<span :class="group.status.held ? $style.warning : $style.hint" :data-held="group.status.held">{{ group.status.text }}</span>
				</li>
			</ul>
		</template>
		<template v-if="waitingRows.length > 0">
			<h5 :class="$style.subheading">
				{{ t('versioniq', 'Waiting for the limit') }}
			</h5>
			<ul :class="$style.list">
				<li v-for="row in waitingRows" :key="row.unit" data-testid="overview-waiting-row">
					{{ waitingText(row.unit, row.since) }}
				</li>
			</ul>
		</template>
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
