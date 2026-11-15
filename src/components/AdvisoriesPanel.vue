<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { AdvisoryAssignee, AdvisoryCorrelation, AdvisoryTriageMap, NcscStatus, TriagedAdvisory, TriagedCorrelation } from '../utils/advisories.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, defineAsyncComponent, ref } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AdvisoryItem from './AdvisoryItem.vue'
import DismissedAdvisories from './DismissedAdvisories.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'
import { applyTriage, isAssignedTo, SERVER_ADVISORY_KEY, splitAdvisoryRows } from '../utils/advisories.ts'
import { currentLoginName } from '../utils/enableApp.ts'

const props = withDefaults(defineProps<{
	advisories: Record<string, AdvisoryCorrelation>
	freshnessLabel: string
	triage?: AdvisoryTriageMap
	/** How far the NCSC-NL read got; null or absent when NCSC-NL checks are off. */
	ncscStatus?: NcscStatus | null
}>(), {
	triage: () => ({}),
	ncscStatus: null,
})
const emit = defineEmits<{
	'update:triage': [triage: AdvisoryTriageMap]
}>()
/**
 * Every advisory the last sweep stored, in one place: the Nextcloud server's
 * own row first (the sweep computed it but no app card could show it, #438),
 * then each app, with the severity of every advisory.
 *
 * Each advisory can be dismissed with a reason, assigned to an owner and,
 * once dismissed, reopened; dismissed advisories stop counting and move to a
 * collapsed Dismissed list per block. A filter shows only the advisories
 * assigned to the current admin, directly or through a group.
 *
 * @spec openspec/specs/security-advisory-correlation/spec.md
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 */
// Loaded when first opened: most visits to the tab never open either dialog.
const DismissAdvisoryDialog = defineAsyncComponent(() => import('../dialogs/DismissAdvisoryDialog.vue'))
const AssignAdvisoryDialog = defineAsyncComponent(() => import('../dialogs/AssignAdvisoryDialog.vue'))

const rows = computed(() => splitAdvisoryRows(props.advisories))

/** "NCSC-NL: catching up, read up to {date}" until a sweep reached the present (advisories-ncsc-feed). */
const ncscCatchingUp = computed(() => {
	const status = props.ncscStatus
	if (!status || !status.enabled || status.caughtUp) {
		return ''
	}

	return status.readUpTo
		? t('versioniq', 'NCSC-NL: catching up, read up to {date}', { date: status.readUpTo.slice(0, 10) })
		: t('versioniq', 'NCSC-NL: not read yet')
})
const server = computed((): TriagedCorrelation | null => rows.value.server
	? applyTriage({ ...rows.value.server, appId: SERVER_ADVISORY_KEY }, props.triage)
	: null)
const apps = computed((): TriagedCorrelation[] => rows.value.apps.map((row) => applyTriage(row, props.triage)))

const onlyMine = ref(false)
const myGroups = ref<string[]>([])
const uid = currentLoginName()

/**
 * Turns the "assigned to me" filter on or off, reading the current admin's
 * groups the first time it is turned on.
 *
 * @param value Whether to show only the advisories assigned to me.
 */
async function setOnlyMine (value: boolean): Promise<void> {
	onlyMine.value = value
	if (!value || uid === '' || myGroups.value.length > 0) {
		return
	}
	try {
		const { payload } = await ocsGet<{ groups?: string[] }>(`/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}/groups`)
		myGroups.value = payload?.groups ?? []
	} catch {
		myGroups.value = []
	}
}

/**
 * The advisories of a row the filter lets through.
 *
 * @param advisories The advisories that still count.
 */
function visible (advisories: TriagedAdvisory[]): TriagedAdvisory[] {
	return onlyMine.value ? advisories.filter((advisory) => isAssignedTo(advisory, uid, myGroups.value)) : advisories
}

const visibleApps = computed(() => onlyMine.value ? apps.value.filter((row) => visible(row.advisories).length > 0) : apps.value)

type Target = { appId: string, advisoryId: string, current?: AdvisoryAssignee | null }
const dismissTarget = ref<Target | null>(null)
const assignTarget = ref<Target | null>(null)
const reopening = ref(false)
const error = ref('')

/**
 * Reopens a dismissed advisory, so it counts again.
 *
 * @param appId The app id, or `:server`.
 * @param advisoryId The advisory id.
 */
async function reopen (appId: string, advisoryId: string): Promise<void> {
	reopening.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsWrite<{ triage: AdvisoryTriageMap }>(
			'POST',
			'/ocs/v2.php/apps/versioniq/api/advisory-triage/reopen',
			{ appId, advisoryId },
		)
		if (apiError) {
			error.value = apiError
			return
		}
		emit('update:triage', payload.triage ?? {})
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not reopen this advisory.')
	} finally {
		reopening.value = false
	}
}
</script>

<template>
	<section :class="$style.panel" data-testid="advisories-panel">
		<h3>{{ t('versioniq', 'Security advisories') }}</h3>
		<p v-if="freshnessLabel" :class="$style.hint">
			{{ freshnessLabel }}
		</p>
		<p v-if="ncscCatchingUp" :class="$style.hint" data-testid="advisories-ncsc-catching-up">
			{{ ncscCatchingUp }}
		</p>
		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="onlyMine"
			data-testid="advisories-only-mine"
			@update:modelValue="setOnlyMine">
			{{ t('versioniq', 'Assigned to me') }}
		</NcCheckboxRadioSwitch>
		<p v-if="error" :class="$style.error" role="alert">
			{{ error }}
		</p>

		<div :class="$style.block" data-testid="advisories-server">
			<h4>{{ t('versioniq', 'Nextcloud server') }}</h4>
			<template v-if="server">
				<p>
					{{ t('versioniq', 'Installed version: {version}', { version: server.installedVersion ?? '' }) }}
				</p>
				<NcNoteCard v-if="server.state === 'pinned-to-vulnerable'" type="error">
					{{ t('versioniq', 'This server version is affected by a published advisory.') }}
					<template v-if="server.recommendedVersion">
						{{ t('versioniq', 'Upgrade to {version} or later.', { version: server.recommendedVersion }) }}
					</template>
				</NcNoteCard>
				<p v-else :class="$style.hint">
					{{ n('versioniq', '%n published advisory is about the server; it does not affect this version.', '%n published advisories are about the server; none affect this version.', server.advisories.length) }}
				</p>
				<details v-if="visible(server.advisories).length > 0" :open="server.state === 'pinned-to-vulnerable'">
					<summary>{{ t('versioniq', 'Show server advisories') }}</summary>
					<ul :class="$style.list">
						<AdvisoryItem
							v-for="advisory in visible(server.advisories)"
							:key="advisory.id"
							:advisory="advisory"
							@dismiss="dismissTarget = { appId: server.appId, advisoryId: advisory.id }"
							@assign="assignTarget = { appId: server.appId, advisoryId: advisory.id, current: advisory.assignee ?? null }" />
					</ul>
				</details>
				<DismissedAdvisories
					:advisories="server.dismissedAdvisories"
					:busy="reopening"
					@reopen="(id: string) => reopen(server!.appId, id)" />
			</template>
			<p v-else :class="$style.hint">
				{{ t('versioniq', 'No published advisory is about this server version.') }}
			</p>
		</div>

		<div :class="$style.block">
			<h4>{{ t('versioniq', 'Apps') }}</h4>
			<p v-if="visibleApps.length === 0" :class="$style.hint">
				{{ onlyMine ? t('versioniq', 'No app advisory is assigned to you.') : t('versioniq', 'No installed app has a published advisory.') }}
			</p>
			<article
				v-for="row in visibleApps"
				:key="row.appId"
				:class="$style.appRow"
				data-testid="advisories-app-row">
				<p :class="$style.appTitle">
					<strong>{{ row.appId }}</strong>
					<span v-if="row.installedVersion">{{ row.installedVersion }}</span>
					<span v-if="row.state === 'pinned-to-vulnerable'" :class="$style.affected">
						{{ t('versioniq', 'Affected') }}
					</span>
					<span v-if="row.recommendedVersion">
						{{ t('versioniq', 'safe version: {version}', { version: row.recommendedVersion }) }}
					</span>
				</p>
				<p v-if="row.error" :class="$style.hint">
					{{ row.error }}
				</p>
				<ul :class="$style.list">
					<AdvisoryItem
						v-for="advisory in visible(row.advisories)"
						:key="advisory.id"
						:advisory="advisory"
						@dismiss="dismissTarget = { appId: row.appId, advisoryId: advisory.id }"
						@assign="assignTarget = { appId: row.appId, advisoryId: advisory.id, current: advisory.assignee ?? null }" />
				</ul>
				<DismissedAdvisories
					v-if="!onlyMine"
					:advisories="row.dismissedAdvisories"
					:busy="reopening"
					@reopen="(id: string) => reopen(row.appId, id)" />
			</article>
		</div>

		<DismissAdvisoryDialog
			v-if="dismissTarget !== null"
			:open="dismissTarget !== null"
			:appId="dismissTarget?.appId ?? ''"
			:advisoryId="dismissTarget?.advisoryId ?? ''"
			@update:open="(value: boolean) => { if (!value) dismissTarget = null }"
			@dismissed="(triage: AdvisoryTriageMap) => emit('update:triage', triage)" />
		<AssignAdvisoryDialog
			v-if="assignTarget !== null"
			:open="assignTarget !== null"
			:appId="assignTarget?.appId ?? ''"
			:advisoryId="assignTarget?.advisoryId ?? ''"
			:current="assignTarget?.current ?? null"
			@update:open="(value: boolean) => { if (!value) assignTarget = null }"
			@assigned="(triage: AdvisoryTriageMap) => emit('update:triage', triage)" />
	</section>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 12px 0;
}

.hint {
	color: var(--color-text-maxcontrast);
}

.block {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.appRow {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.appTitle {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.error {
	color: var(--color-error-text);
}

.affected {
	color: var(--color-error-text);
	font-weight: bold;
}

.list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
}
</style>
