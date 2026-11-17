<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { OcsResult } from '../ocs.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { ocsWrite } from '../ocs.ts'

/**
 * Update groups and the two limits in the Automatic updates block
 * (autoupdate-run-batching D6): the groups with their apps in install order,
 * a form to add one (a name and the apps), up and down buttons for the
 * order, and a Save that sends the whole list. The per-run and weekly limits
 * are two number fields with their own Save.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
export type UpdateGroupView = { id?: string, name: string, apps: string[] }
type Settings = { groups?: UpdateGroupView[], maxPerRun?: number | null, maxPerWeek?: number | null }
type Option = { id: string, label: string }

const props = defineProps<{
	/** The apps that can join a group: those with an automatic update policy. */
	apps: string[]
	labels: Record<string, string>
	/** The stored groups and limits, from GET /api/policies (the page loads it). */
	groups: UpdateGroupView[]
	maxPerRun: number | null
	maxPerWeek: number | null
}>()

const emit = defineEmits<{
	(event: 'saved'): void
}>()

const GROUPS = '/ocs/v2.php/apps/versioniq/api/auto-update/groups'
const SETTINGS = '/ocs/v2.php/apps/versioniq/api/auto-update/settings'

const draftGroups = ref<UpdateGroupView[]>([])
const newName = ref('')
const newApps = ref<Option[]>([])
const runLimit = ref<string | number>('')
const weekLimit = ref<string | number>('')
const saving = ref(false)
const error = ref('')
const notice = ref('')

/**
 * @param appId The app id.
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function labelFor (appId: string): string {
	return props.labels[appId] ?? appId
}

// An app is in at most one group, so the form offers only the others.
const options = computed<Option[]>(() => {
	const taken = new Set(draftGroups.value.flatMap((group) => group.apps))
	return props.apps
		.filter((appId) => !taken.has(appId))
		.map((appId) => ({ id: appId, label: labelFor(appId) }))
		.sort((a, b) => a.label.localeCompare(b.label))
})

/**
 * @param value A limit from the server.
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function limitText (value: number | null | undefined): string {
	return value === null || value === undefined ? '' : String(value)
}

/**
 * @param data What the server returned.
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function fill (data: Settings): void {
	if (data.groups) {
		draftGroups.value = data.groups.map((group) => ({ ...group, apps: [...group.apps] }))
	}
	if ('maxPerRun' in data) {
		runLimit.value = limitText(data.maxPerRun)
	}
	if ('maxPerWeek' in data) {
		weekLimit.value = limitText(data.maxPerWeek)
	}
}

/**
 * Adds the group in the form to the list; Save sends it.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function addGroup (): void {
	error.value = ''
	notice.value = ''
	const name = newName.value.trim()
	if (name === '' || newApps.value.length < 2) {
		error.value = t('versioniq', 'A group needs a name and at least two apps.')
		return
	}
	draftGroups.value.push({ name, apps: newApps.value.map((option) => option.id) })
	newName.value = ''
	newApps.value = []
}

/**
 * @param groupIndex The group.
 * @param appIndex The app's place in it.
 * @param delta -1 to move up, 1 to move down.
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function move (groupIndex: number, appIndex: number, delta: number): void {
	const apps = draftGroups.value[groupIndex].apps
	const target = appIndex + delta
	if (target < 0 || target >= apps.length) {
		return
	}
	[apps[appIndex], apps[target]] = [apps[target], apps[appIndex]]
}

/**
 * @param groupIndex The group to remove.
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function removeGroup (groupIndex: number): void {
	draftGroups.value.splice(groupIndex, 1)
}

/**
 * The body PUT /api/auto-update/groups receives: the whole list, in order.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function groupsPayload (): Record<string, unknown> {
	return {
		groups: draftGroups.value.map((group) => ({ ...(group.id ? { id: group.id } : {}), name: group.name, apps: [...group.apps] })),
	}
}

/**
 * @param work The request to send.
 * @param done The message when it succeeded.
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
async function send (work: () => Promise<OcsResult<Settings>>, done: string): Promise<void> {
	saving.value = true
	error.value = ''
	notice.value = ''
	try {
		const { payload, error: apiError } = await work()
		if (apiError) {
			error.value = apiError
			return
		}
		fill(payload)
		notice.value = done
		emit('saved')
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not save.')
	} finally {
		saving.value = false
	}
}

/**
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function saveGroups (): Promise<void> {
	return send(() => ocsWrite<Settings>('PUT', GROUPS, groupsPayload()), t('versioniq', 'Update groups saved.'))
}

/**
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
function saveLimits (): Promise<void> {
	return send(
		// A number field's v-model gives a number once edited, so read it as text.
		() => ocsWrite<Settings>('PUT', SETTINGS, { maxPerRun: String(runLimit.value).trim(), maxPerWeek: String(weekLimit.value).trim() }),
		t('versioniq', 'Update limits saved.'),
	)
}

// The page reloads the policies after a save; take the stored state each time.
watch(() => [props.groups, props.maxPerRun, props.maxPerWeek], () => {
	fill({ groups: props.groups, maxPerRun: props.maxPerRun, maxPerWeek: props.maxPerWeek })
}, { immediate: true })
</script>

<template>
	<section :class="$style.panel" data-testid="update-groups-panel">
		<h4>{{ t('versioniq', 'Update limits') }}</h4>
		<p :class="$style.hint">
			{{ t('versioniq', 'How many automatic updates may install in one run and in seven days. Updates over a limit wait and go first next time.') }}
		</p>
		<div :class="$style.row">
			<label :class="$style.field" for="update-limit-run">
				<span>{{ t('versioniq', 'Per run (1-50, empty for no limit)') }}</span>
				<input id="update-limit-run"
					v-model="runLimit"
					type="number"
					min="1"
					max="50"
					data-testid="update-limit-run">
			</label>
			<label :class="$style.field" for="update-limit-week">
				<span>{{ t('versioniq', 'Per seven days (1-200, empty for no limit)') }}</span>
				<input id="update-limit-week"
					v-model="weekLimit"
					type="number"
					min="1"
					max="200"
					data-testid="update-limit-week">
			</label>
		</div>
		<div>
			<NcButton variant="secondary"
				:disabled="saving"
				data-testid="update-limits-save"
				@click="saveLimits">
				{{ t('versioniq', 'Save limits') }}
			</NcButton>
		</div>

		<h4>{{ t('versioniq', 'Update groups') }}</h4>
		<p :class="$style.hint">
			{{ t('versioniq', 'Apps in a group update together, in this order. If one of them cannot update, none of them do, and a failed update stops the rest of the group.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			<span data-testid="update-groups-error">{{ error }}</span>
		</NcNoteCard>
		<p v-if="draftGroups.length === 0" :class="$style.hint" data-testid="update-groups-none">
			{{ t('versioniq', 'No update groups yet.') }}
		</p>
		<div v-for="(group, groupIndex) in draftGroups"
			:key="group.id ?? `new-${groupIndex}`"
			:class="$style.group"
			data-testid="update-group">
			<div :class="$style.groupHeader">
				<strong data-testid="update-group-name">{{ group.name }}</strong>
				<NcButton variant="tertiary"
					:aria-label="t('versioniq', 'Remove group {name}', { name: group.name })"
					data-testid="update-group-remove"
					@click="removeGroup(groupIndex)">
					{{ t('versioniq', 'Remove') }}
				</NcButton>
			</div>
			<ol :class="$style.apps">
				<li v-for="(appId, appIndex) in group.apps" :key="appId" data-testid="update-group-app">
					<span>{{ labelFor(appId) }}</span>
					<NcButton variant="tertiary"
						:disabled="appIndex === 0"
						:aria-label="t('versioniq', 'Move {app} up', { app: labelFor(appId) })"
						data-testid="update-group-up"
						@click="move(groupIndex, appIndex, -1)">
						↑
					</NcButton>
					<NcButton variant="tertiary"
						:disabled="appIndex === group.apps.length - 1"
						:aria-label="t('versioniq', 'Move {app} down', { app: labelFor(appId) })"
						data-testid="update-group-down"
						@click="move(groupIndex, appIndex, 1)">
						↓
					</NcButton>
				</li>
			</ol>
		</div>

		<fieldset :class="$style.form">
			<legend>{{ t('versioniq', 'New group') }}</legend>
			<label :class="$style.field" for="update-group-new-name">
				<span>{{ t('versioniq', 'Name') }}</span>
				<input id="update-group-new-name" v-model="newName" type="text" data-testid="update-group-new-name">
			</label>
			<NcSelect v-model="newApps"
				:inputLabel="t('versioniq', 'Apps, in install order')"
				:options="options"
				:multiple="true"
				label="label"
				data-testid="update-group-new-apps" />
			<div>
				<NcButton variant="secondary" data-testid="update-group-add" @click="addGroup">
					{{ t('versioniq', 'Add group') }}
				</NcButton>
			</div>
		</fieldset>

		<p v-if="notice" :class="$style.notice" data-testid="update-groups-notice">
			{{ notice }}
		</p>
		<div>
			<NcButton variant="primary"
				:disabled="saving"
				data-testid="update-groups-save"
				@click="saveGroups">
				{{ t('versioniq', 'Save groups') }}
			</NcButton>
		</div>
	</section>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 720px;
	margin-top: 12px;
}

.row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.group {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.groupHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.apps {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 4px 0 0;
	padding-inline-start: 20px;
}

.apps li {
	display: flex;
	align-items: center;
	gap: 4px;
}

.form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.hint {
	color: var(--color-text-maxcontrast);
}

.notice {
	color: var(--color-success-text);
}
</style>
