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
	disabled?: boolean
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
 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/auto-update-policies/spec.md#requirement-the-overview-lists-the-updates-that-are-pending-now
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
		<p v-if="scheduled.length === 0" :class="$style.hint">
			{{ t('versioniq', 'No app has an automatic update policy.') }}
		</p>
		<ul v-else :class="$style.list">
			<li v-for="row in scheduled" :key="row.appId" data-testid="overview-scheduled-row">
				<strong>{{ labelFor(row.appId) }}</strong>
				{{ t('versioniq', 'policy: {level}', { level: levelLabel(row.level) }) }}
			</li>
		</ul>
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
