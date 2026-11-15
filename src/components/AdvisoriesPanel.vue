<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { AdvisoryAssignee, AdvisoryCorrelation, AdvisoryHistoryEntry, AdvisoryTriageMap, Compliance, TriagedAdvisory, TriagedCorrelation } from '../utils/advisories.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, defineAsyncComponent, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AdvisoryItem from './AdvisoryItem.vue'
import DismissedAdvisories from './DismissedAdvisories.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'
import { advisoryAnchor, advisoryHistory, applyTriage, complianceSentence, fixSentence, isAssignedTo, RECENT_ADVISORIES, SERVER_ADVISORY_KEY, splitAdvisoryRows } from '../utils/advisories.ts'
import { currentLoginName } from '../utils/enableApp.ts'

const props = withDefaults(defineProps<{
	advisories: Record<string, AdvisoryCorrelation>
	freshnessLabel: string
	triage?: AdvisoryTriageMap
	compliance?: Compliance | null
}>(), {
	triage: () => ({}),
	compliance: null,
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

const complianceLine = computed(() => complianceSentence(props.compliance))

/** The targets whose whole history is shown, not only the most recent ones. */
const expanded = ref<Record<string, boolean>>({})

/** One history entry, with its triaged advisory while it still counts. */
type HistoryItem = { entry: AdvisoryHistoryEntry, triaged: TriagedAdvisory | null }

/**
 * A target's history, newest first, without the advisories a dismissal holds
 * (those are in its Dismissed list) and, with the filter on, only the ones
 * assigned to me. An entry that still counts carries its triaged advisory, so
 * it can be dismissed or assigned; one the installed version is not affected
 * by carries none.
 *
 * @param row The target's triaged correlation.
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
 */
function historyItems (row: TriagedCorrelation): HistoryItem[] {
	const dismissed = new Set(row.dismissedAdvisories.map((advisory) => advisory.id))
	const counting = new Map(row.advisories.map((advisory) => [advisory.id, advisory]))
	const items = advisoryHistory(row as AdvisoryCorrelation)
		.filter((entry) => !dismissed.has(entry.id))
		.map((entry) => ({ entry, triaged: counting.get(entry.id) ?? null }))
	return onlyMine.value
		? items.filter((item) => item.triaged !== null && isAssignedTo(item.triaged, uid, myGroups.value))
		: items
}

/**
 * The entries of a target to render: the most recent, or all once expanded.
 *
 * @param key The target key.
 * @param row The target's triaged correlation.
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
 */
function shownHistory (key: string, row: TriagedCorrelation): HistoryItem[] {
	const items = historyItems(row)
	return expanded.value[key] ? items : items.slice(0, RECENT_ADVISORIES)
}

/**
 * Shows every advisory of a target.
 *
 * @param key The target key.
 * @spec openspec/changes/advisories-per-app-detail/tasks.md#4.1
 */
function showAll (key: string): void {
	expanded.value = { ...expanded.value, [key]: true }
}
</script>

<template>
	<section :class="$style.panel" data-testid="advisories-panel">
		<h3>{{ t('versioniq', 'Security advisories') }}</h3>
		<p v-if="complianceLine"
			:class="[$style.compliance, { [$style.affected]: compliance?.status === 'not_compliant' }]"
			data-testid="advisories-compliance">
			{{ complianceLine }}
		</p>
		<p v-if="freshnessLabel" :class="$style.hint">
			{{ freshnessLabel }}
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
					<template v-if="fixSentence(server, true)">
						{{ fixSentence(server, true) }}
					</template>
					<template v-else-if="server.recommendedVersion">
						{{ t('versioniq', 'Upgrade to {version} or later.', { version: server.recommendedVersion }) }}
					</template>
				</NcNoteCard>
				<p v-else :class="$style.hint">
					{{ n('versioniq', '%n published advisory is about the server; it does not affect this version.', '%n published advisories are about the server; none affect this version.', server.advisories.length) }}
				</p>
				<details v-if="historyItems(server).length > 0" :open="server.state === 'pinned-to-vulnerable'">
					<summary>{{ t('versioniq', 'Show server advisories') }}</summary>
					<ul :class="$style.list">
						<AdvisoryItem
							v-for="item in shownHistory(':server', server)"
							:key="item.entry.id"
							:advisory="item.triaged ?? item.entry"
							:entry="item.entry"
							:readOnly="item.triaged === null"
							@dismiss="dismissTarget = { appId: server.appId, advisoryId: item.entry.id }"
							@assign="assignTarget = { appId: server.appId, advisoryId: item.entry.id, current: item.triaged?.assignee ?? null }" />
					</ul>
					<NcButton v-if="!expanded[':server'] && historyItems(server).length > RECENT_ADVISORIES"
						variant="tertiary"
						data-testid="advisories-show-all"
						@click="showAll(':server')">
						{{ t('versioniq', 'Show all {count}', { count: String(historyItems(server).length) }) }}
					</NcButton>
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
				:id="advisoryAnchor(row.appId)"
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
				<p v-if="fixSentence(row)" :class="$style.affected" data-testid="advisory-fix">
					{{ fixSentence(row) }}
				</p>
				<p v-if="row.error" :class="$style.hint">
					{{ row.error }}
				</p>
				<ul :class="$style.list">
					<AdvisoryItem
						v-for="item in shownHistory(row.appId, row)"
						:key="item.entry.id"
						:advisory="item.triaged ?? item.entry"
						:entry="item.entry"
						:readOnly="item.triaged === null"
						@dismiss="dismissTarget = { appId: row.appId, advisoryId: item.entry.id }"
						@assign="assignTarget = { appId: row.appId, advisoryId: item.entry.id, current: item.triaged?.assignee ?? null }" />
				</ul>
				<NcButton v-if="!expanded[row.appId] && historyItems(row).length > RECENT_ADVISORIES"
					variant="tertiary"
					data-testid="advisories-show-all"
					@click="showAll(row.appId)">
					{{ t('versioniq', 'Show all {count}', { count: String(historyItems(row).length) }) }}
				</NcButton>
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

.compliance {
	font-weight: bold;
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
