<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
The rollback section of the History tab (install-bulk-rollback design D4):
the snapshots, newest first, a date that picks the newest snapshot taken on
or before it, taking a (named) snapshot, deleting one, and opening the plan
to roll back to one.
@spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
-->
<script setup lang="ts">
import type { SnapshotSummary } from '../dialogs/RollbackPlanDialog.vue'
import type { RollbackStep } from '../utils/rollbackRun.ts'

import { n, t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import RollbackPlanDialog from '../dialogs/RollbackPlanDialog.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'
import { errorMessage } from '../utils/errorMessage.ts'

const SNAPSHOTS = '/ocs/v2.php/apps/versioniq/api/snapshots'

const snapshots = ref<SnapshotSummary[]>([])
const name = ref('')
const date = ref('')
const error = ref('')
const busy = ref(false)
const dialogOpen = ref(false)
const planSnapshot = ref<SnapshotSummary | null>(null)
const planSteps = ref<RollbackStep[]>([])
const pickedFor = ref('')

/**
 * A readable label for a snapshot's reason.
 *
 * @param reason The stored reason.
 */
function reasonLabel (reason: string): string {
	switch (reason) {
	case 'daily': return t('versioniq', 'Daily')
	case 'before_auto_update': return t('versioniq', 'Before automatic updates')
	case 'before_rollback': return t('versioniq', 'Before a rollback')
	default: return t('versioniq', 'Taken by an admin')
	}
}

/**
 * Runs a request and shows its error; returns false when it failed.
 *
 * @param work The request.
 */
async function guarded (work: () => Promise<string | undefined>): Promise<boolean> {
	busy.value = true
	error.value = ''
	try {
		const apiError = await work()
		if (apiError) {
			error.value = apiError
			return false
		}
		return true
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'The snapshots could not be loaded.'))
		return false
	} finally {
		busy.value = false
	}
}

/**
 * Loads the snapshots.
 */
async function load (): Promise<void> {
	await guarded(async () => {
		const { payload, error: apiError } = await ocsGet<{ snapshots?: SnapshotSummary[] }>(SNAPSHOTS)
		snapshots.value = Array.isArray(payload?.snapshots) ? payload.snapshots : []
		return apiError
	})
}

/**
 * Takes a snapshot now, with the name when one is given.
 */
async function take (): Promise<void> {
	const ok = await guarded(async () => (await ocsWrite('POST', SNAPSHOTS, { name: name.value.trim() })).error)
	if (ok) {
		name.value = ''
		await load()
	}
}

/**
 * Deletes a snapshot.
 *
 * @param id The snapshot id.
 */
async function remove (id: string): Promise<void> {
	if (await guarded(async () => (await ocsWrite('DELETE', `${SNAPSHOTS}/${encodeURIComponent(id)}`)).error)) {
		await load()
	}
}

/**
 * Opens the plan to roll back to a snapshot.
 *
 * @param snapshot The snapshot.
 * @param forDate The date it was picked for, if any.
 */
async function openPlan (snapshot: SnapshotSummary, forDate = ''): Promise<void> {
	await guarded(async () => {
		const { payload, error: apiError } = await ocsGet<{ steps?: RollbackStep[] }>(`${SNAPSHOTS}/${encodeURIComponent(snapshot.id)}/plan`)
		if (!apiError) {
			planSnapshot.value = snapshot
			planSteps.value = Array.isArray(payload?.steps) ? payload.steps : []
			pickedFor.value = forDate
			dialogOpen.value = true
		}
		return apiError
	})
}

/**
 * Picks the newest snapshot on or before the date and opens its plan.
 */
async function pickDate (): Promise<void> {
	let picked: SnapshotSummary | null = null
	const ok = await guarded(async () => {
		const { payload, error: apiError } = await ocsGet<{ picked?: SnapshotSummary | null }>(SNAPSHOTS, { at: date.value })
		picked = payload?.picked ?? null
		return apiError ?? (picked === null ? t('versioniq', 'No snapshot was taken on or before {date}.', { date: date.value }) : undefined)
	})
	if (ok && picked !== null) {
		await openPlan(picked, date.value)
	}
}

onMounted(load)
</script>

<template>
	<section :class="$style.rollback" :aria-label="t('versioniq', 'Roll back')">
		<h3>{{ t('versioniq', 'Roll back to a snapshot') }}</h3>
		<p :class="$style.hint">
			{{ t('versioniq', 'A snapshot records the version of every app. Versioniq takes one every day and before automatic updates.') }}
		</p>
		<div :class="$style.row">
			<NcTextField v-model="name" :label="t('versioniq', 'Snapshot name (optional)')" :disabled="busy" />
			<NcButton :disabled="busy" @click="take">
				{{ t('versioniq', 'Take a snapshot') }}
			</NcButton>
		</div>
		<div :class="$style.row">
			<label :class="$style.dateLabel">
				{{ t('versioniq', 'The state on') }}
				<input v-model="date" type="date" :disabled="busy" data-test="date">
			</label>
			<NcButton :disabled="busy || date === ''" @click="pickDate">
				{{ t('versioniq', 'Show the plan') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="snapshots.length === 0 && !busy" :class="$style.hint">
			{{ t('versioniq', 'No snapshots yet.') }}
		</p>
		<ul v-else :class="$style.list">
			<li v-for="snapshot in snapshots" :key="snapshot.id" :data-snapshot="snapshot.id">
				<span>{{ snapshot.takenAt }}</span>
				<span>{{ snapshot.name ?? reasonLabel(snapshot.reason) }}</span>
				<span>{{ n('versioniq', '%n app', '%n apps', snapshot.appCount) }}</span>
				<NcButton variant="tertiary" :disabled="busy" @click="openPlan(snapshot)">
					{{ t('versioniq', 'Plan') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" :aria-label="t('versioniq', 'Delete snapshot')" @click="remove(snapshot.id)">
					{{ t('versioniq', 'Delete') }}
				</NcButton>
			</li>
		</ul>
		<RollbackPlanDialog v-model:open="dialogOpen"
			:snapshot="planSnapshot"
			:steps="planSteps"
			:pickedFor="pickedFor"
			@finished="load" />
	</section>
</template>

<style module>
.rollback {
	margin-bottom: 16px;
}

.hint {
	color: var(--color-text-maxcontrast);
}

.row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin: 8px 0;
}

.dateLabel {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.list {
	list-style: none;
	padding: 0;
}

.list li {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}
</style>
