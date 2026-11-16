<!--
SPDX-License-Identifier: EUPL-1.2
Update all: lists the apps with a newer version this server can run, ticked
by default except pinned apps, and shows each outcome as it lands. The run
itself is the caller's (updateBatch.ts through the normal install path);
this dialog only picks the apps, starts and stops it.
@spec openspec/changes/install-one-click-updates/specs/version-management/spec.md#requirement-an-admin-updates-one-app-or-all-apps-in-one-action
-->
<script setup lang="ts">
import type { BatchCandidate, BatchRow } from '../utils/updateBatch.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'

const props = defineProps<{
	open: boolean
	candidates: BatchCandidate[]
	/** The outcome per app id, filled while the run goes. */
	rows: Record<string, BatchRow>
	running: boolean
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	start: [selected: BatchCandidate[]]
	stop: []
}>()

const ticked = ref<Record<string, boolean>>({})
const stopping = ref(false)

watch(() => [props.open, props.candidates] as const, () => {
	if (props.open && !props.running) {
		ticked.value = Object.fromEntries(props.candidates.map((candidate) => [candidate.appId, candidate.selected]))
		stopping.value = false
	}
}, { immediate: true })

const selected = computed(() => props.candidates
	.filter((candidate) => ticked.value[candidate.appId] === true)
	.map((candidate) => ({ ...candidate, selected: true })))

const hasRun = computed(() => Object.keys(props.rows).length > 0)

/**
 * Ticks or unticks one app before the run starts.
 *
 * @param appId The app.
 * @param value Whether it is ticked.
 */
function toggle (appId: string, value: boolean): void {
	ticked.value = { ...ticked.value, [appId]: value }
}

/**
 * Closes the dialog; a running batch is asked to stop after the current install first.
 */
function close (): void {
	if (props.running) {
		stopping.value = true
		emit('stop')
		return
	}
	emit('update:open', false)
}

/**
 * The text a row shows for its outcome.
 *
 * @param candidate The listed app.
 */
function rowStatus (candidate: BatchCandidate): string {
	const row = props.rows[candidate.appId]
	if (!row) {
		return candidate.pinned && ticked.value[candidate.appId] !== true
			? t('versioniq', 'Pinned to {version}', { version: candidate.pinnedVersion ?? candidate.fromVersion })
			: ''
	}
	switch (row.state) {
	case 'running':
		return t('versioniq', 'Updating…')
	case 'updated':
		return t('versioniq', 'Updated to {version}', { version: row.toVersion })
	case 'upToDate':
		return t('versioniq', 'Already up to date')
	case 'failed':
		return row.category
			? t('versioniq', 'Failed ({category}): {message}', { category: row.category, message: row.message ?? '' })
			: t('versioniq', 'Failed: {message}', { message: row.message ?? '' })
	case 'skipped':
		return t('versioniq', 'Skipped')
	default:
		return t('versioniq', 'Waiting')
	}
}

const buttons = computed(() => [
	{
		label: props.running ? t('versioniq', 'Stop after this install') : t('versioniq', 'Close'),
		variant: 'tertiary' as const,
		disabled: stopping.value && props.running,
		callback: close,
	},
	...(hasRun.value
		? []
		: [{
				label: n('versioniq', 'Update %n app', 'Update %n apps', selected.value.length),
				variant: 'primary' as const,
				disabled: selected.value.length === 0 || props.running,
				callback: () => emit('start', selected.value),
			}]),
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Update all apps')"
		:buttons="buttons"
		@update:open="(value: boolean) => { if (!value) { close() } }">
		<p :class="$style.text">
			{{ t('versioniq', 'Apps are installed one after the other. Each install switches maintenance mode on and off, so users may see short maintenance pauses.') }}
		</p>
		<ul :class="$style.list">
			<li
				v-for="candidate in candidates"
				:key="candidate.appId"
				:class="$style.row"
				:data-testid="`update-all-row-${candidate.appId}`">
				<label :class="$style.label">
					<input
						type="checkbox"
						:checked="ticked[candidate.appId] === true"
						:disabled="running || hasRun"
						@change="toggle(candidate.appId, ($event.target as HTMLInputElement).checked)">
					{{ t('versioniq', '{appId}: {fromVersion} to {toVersion}', { appId: candidate.appId, fromVersion: candidate.fromVersion, toVersion: candidate.toVersion }) }}
				</label>
				<span :class="$style.status" data-testid="update-all-status">{{ rowStatus(candidate) }}</span>
			</li>
		</ul>
		<p v-if="stopping && running" :class="$style.text" data-testid="update-all-stopping">
			{{ t('versioniq', 'The run stops after the current install.') }}
		</p>
	</NcDialog>
</template>

<style module>
.text {
	font-size: 13px;
	line-height: 1.4;
}

.list {
	margin: 8px 0;
	padding: 0;
	list-style: none;
}

.row {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 8px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.label {
	display: flex;
	gap: 6px;
	align-items: center;
}

.status {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
