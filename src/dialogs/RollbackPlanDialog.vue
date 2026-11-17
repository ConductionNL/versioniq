<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2
The plan to roll the instance back to a snapshot (install-bulk-rollback
design D4): one row per app with what happens to it. Check runs a dry-run
install of every downgrade and shows the migrations it would orphan. Run
takes a before_rollback snapshot, asks for the password once, records the
start, installs the steps one at a time through the standard install and
records the end. It stops at the first failure and offers to continue with
the rest; closing the dialog stops after the current install.
@spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
-->
<script setup lang="ts">
import type { InstallStep, RollbackStep, StepOutcome } from '../utils/rollbackRun.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ensurePasswordConfirmation, ocsWrite } from '../ocs.ts'
import { errorMessage } from '../utils/errorMessage.ts'
import { installStep, isRunnable, runCounts, runRollback } from '../utils/rollbackRun.ts'

export type SnapshotSummary = { id: string, takenAt: string, reason: string, name: string | null, appCount: number }

const props = withDefaults(defineProps<{
	open: boolean
	snapshot: SnapshotSummary | null
	steps: RollbackStep[]
	/** Set when the snapshot was picked by a date. */
	pickedFor?: string
	/** The install of one step; injectable for tests. */
	install?: InstallStep
}>(), { pickedFor: '', install: installStep })

const emit = defineEmits<{
	'update:open': [value: boolean]
	finished: []
}>()

const SNAPSHOTS = '/ocs/v2.php/apps/versioniq/api/snapshots'

const movePins = ref(false)
const busy = ref(false)
const outcomes = ref(new Map<number, StepOutcome>())
const failedAt = ref<number | null>(null)
const summary = ref('')
const error = ref('')
let stopRequested = false

watch(() => props.open, (open) => {
	if (open) {
		movePins.value = false
		outcomes.value = new Map()
		failedAt.value = null
		summary.value = ''
		error.value = ''
		stopRequested = false
	}
})

const runnableCount = computed(() => props.steps.filter((step) => isRunnable(step, movePins.value)).length)
const hasPinned = computed(() => props.steps.some((step) => step.kind === 'pinned'))

/**
 * The label of a step's kind.
 *
 * @param step A plan step.
 */
function kindLabel (step: RollbackStep): string {
	switch (step.kind) {
	case 'downgrade': return t('versioniq', 'Moves back')
	case 'upgrade': return t('versioniq', 'Moves forward')
	case 'pinned': return t('versioniq', 'Pinned to {version}', { version: step.pinnedTo ?? '' })
	case 'removed': return t('versioniq', 'Removed since; left alone')
	case 'added': return t('versioniq', 'Added since; left alone')
	default: return t('versioniq', 'Unchanged')
	}
}

/**
 * What happened to a step in the check or the run.
 *
 * @param index The step's index.
 */
function outcomeText (index: number): string {
	const outcome = outcomes.value.get(index)
	if (!outcome) {
		return ''
	}
	if (outcome.outcome === 'failed') {
		return t('versioniq', 'Failed: {message}', { message: outcome.message })
	}
	if (outcome.outcome === 'checked') {
		return outcome.orphanedMigrations.length === 0
			? t('versioniq', 'No migrations orphaned')
			: t('versioniq', 'Would orphan: {migrations}', { migrations: outcome.orphanedMigrations.join(', ') })
	}
	return t('versioniq', 'Done')
}

/**
 * Records one outcome, keeping the map reactive.
 *
 * @param index The step's index.
 * @param outcome Its outcome.
 */
function record (index: number, outcome: StepOutcome): void {
	outcomes.value = new Map(outcomes.value).set(index, outcome)
}

/**
 * Runs a dry-run install of every downgrade step; changes nothing.
 */
async function check (): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		await ensurePasswordConfirmation()
		await runRollback(props.steps, { movePins: movePins.value, dryRun: true, onStep: record, install: props.install })
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'The rollback could not be completed.'))
	} finally {
		busy.value = false
	}
}

/**
 * Records the start or the end of the run in the audit trail.
 *
 * @param body The phase and its counts.
 */
async function runLog (body: Record<string, unknown>): Promise<void> {
	const { error: apiError } = await ocsWrite('POST', `${SNAPSHOTS}/${encodeURIComponent(props.snapshot?.id ?? '')}/run-log`, body)
	if (apiError) {
		throw new Error(apiError)
	}
}

/**
 * Takes the before_rollback snapshot; asks for the password once.
 */
async function snapshotBeforeRun (): Promise<void> {
	const { error: apiError } = await ocsWrite('POST', SNAPSHOTS, { reason: 'before_rollback' })
	if (apiError) {
		throw new Error(apiError)
	}
}

/**
 * Runs the plan from a step on; the first run also takes the snapshot and
 * records the start.
 *
 * @param startAt The index to start at.
 */
async function run (startAt = 0): Promise<void> {
	busy.value = true
	error.value = ''
	failedAt.value = null
	try {
		if (startAt === 0) {
			outcomes.value = new Map()
			await snapshotBeforeRun()
			await runLog({ phase: 'start', steps: runnableCount.value })
		}
		const result = await runRollback(props.steps, { movePins: movePins.value, dryRun: false, startAt, shouldStop: () => stopRequested, onStep: record, install: props.install })
		failedAt.value = result.failedAt
		if (result.failedAt === null) {
			await finish()
		}
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'The rollback could not be completed.'))
	} finally {
		busy.value = false
	}
}

/**
 * Records the end of the run with its counts.
 */
async function finish (): Promise<void> {
	const counts = runCounts(props.steps, { outcomes: outcomes.value, movePins: movePins.value })
	await runLog({ phase: 'end', ...counts })
	summary.value = t('versioniq', '{succeeded} succeeded, {failed} failed, {skipped} skipped', counts)
	emit('finished')
}

/**
 * Stops at the failure: the end of the run is recorded with the rest skipped.
 */
async function stopHere (): Promise<void> {
	busy.value = true
	try {
		failedAt.value = null
		await finish()
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'The rollback could not be completed.'))
	} finally {
		busy.value = false
	}
}

/**
 * Closing stops the run after the current install.
 */
function close (): void {
	stopRequested = true
	emit('update:open', false)
}

const buttons = computed(() => failedAt.value !== null
	? [
		{ label: t('versioniq', 'Stop here'), type: 'tertiary' as const, disabled: busy.value, callback: () => { stopHere() } },
		{ label: t('versioniq', 'Continue with the rest'), variant: 'primary' as const, disabled: busy.value, callback: () => { run((failedAt.value ?? 0) + 1) } },
	]
	: [
		{ label: t('versioniq', 'Close'), type: 'tertiary' as const, disabled: false, callback: () => close() },
		{ label: t('versioniq', 'Check'), type: 'secondary' as const, disabled: busy.value || summary.value !== '', callback: () => { check() } },
		{ label: t('versioniq', 'Run'), variant: 'error' as const, disabled: busy.value || summary.value !== '' || runnableCount.value === 0, callback: () => { run(0) } },
	])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Roll back to a snapshot')"
		size="large"
		:buttons="buttons"
		@update:open="(value: boolean) => { if (!value) { close() } }">
		<p v-if="snapshot" :class="$style.intro">
			<template v-if="pickedFor">
				{{ t('versioniq', 'The newest snapshot on or before {date} was taken on {takenAt}.', { date: pickedFor, takenAt: snapshot.takenAt }) }}
			</template>
			<template v-else>
				{{ t('versioniq', 'Snapshot taken on {takenAt}.', { takenAt: snapshot.takenAt }) }}
			</template>
			{{ snapshot.name ?? '' }}
		</p>
		<NcCheckboxRadioSwitch v-if="hasPinned" v-model="movePins" :disabled="busy" data-test="move-pins">
			{{ t('versioniq', 'Move the pins of pinned apps') }}
		</NcCheckboxRadioSwitch>
		<table :class="$style.plan">
			<thead>
				<tr>
					<th scope="col">
						{{ t('versioniq', 'App') }}
					</th>
					<th scope="col">
						{{ t('versioniq', 'Now') }}
					</th>
					<th scope="col">
						{{ t('versioniq', 'In the snapshot') }}
					</th>
					<th scope="col">
						{{ t('versioniq', 'Step') }}
					</th>
					<th scope="col">
						{{ t('versioniq', 'Result') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(step, index) in steps" :key="step.appId" :data-app="step.appId">
					<td>{{ step.appId }}</td>
					<td>{{ step.from ?? '—' }}</td>
					<td>{{ step.to ?? '—' }}</td>
					<td>{{ kindLabel(step) }}</td>
					<td data-test="outcome">
						{{ outcomeText(index) }}
					</td>
				</tr>
			</tbody>
		</table>
		<NcNoteCard v-if="failedAt !== null" type="error">
			{{ t('versioniq', 'The run stopped at {app}. Apps often depend on each other; continue only when the rest can run without it.', { app: steps[failedAt]?.appId ?? '' }) }}
		</NcNoteCard>
		<NcNoteCard v-if="summary" type="success">
			{{ summary }}
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</NcDialog>
</template>

<style module>
.intro {
	margin: 0 0 8px;
}

.plan {
	width: 100%;
	border-collapse: collapse;
	margin: 8px 0;
}

.plan th,
.plan td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}
</style>
