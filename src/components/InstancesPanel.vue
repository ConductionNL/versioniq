<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type {InstanceColumn, InstanceConnection, Manifest, SelfInstance} from '../utils/instanceMatrix.ts';

import { getCanonicalLocale, n, t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import InstanceConnectionDialog from '../dialogs/InstanceConnectionDialog.vue'
import PromoteDialog from '../dialogs/PromoteDialog.vue'
import { ocsGet, ocsPost, ocsWrite } from '../ocs.ts'
import {
					canPromoteFrom,
	daysSince,
	instanceColumns,
	instanceRows} from '../utils/instanceMatrix.ts'

/**
 * The Instances tab (install-multi-instance-promotion D5, D6): every
 * connected instance's app versions beside this one's, in chain order, with
 * the connections managed here and a promotion from an earlier stage.
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
 */

type InstancesPayload = { self: SelfInstance, instances: InstanceConnection[] }

const emit = defineEmits<{
	rollback: [target: { appId: string, version: string }]
}>()

const INSTANCES = '/ocs/v2.php/apps/versioniq/api/instances'
const MANIFEST = '/ocs/v2.php/apps/versioniq/api/instance/manifest'

const self = ref<SelfInstance | null>(null)
const connections = ref<InstanceConnection[]>([])
const own = ref<Manifest | null>(null)
const loading = ref(false)
const refreshing = ref(false)
const error = ref('')
const differsOnly = ref(false)

const connectionDialogOpen = ref(false)
const editing = ref<InstanceConnection | null>(null)

const promoteOpen = ref(false)
const promoteTarget = ref<{ appId: string, connection: { id: string, name: string, stage: string } } | null>(null)

const columns = computed((): InstanceColumn[] => self.value ? instanceColumns(self.value, own.value, connections.value) : [])
const rows = computed(() => instanceRows(columns.value))
const shownRows = computed(() => differsOnly.value ? rows.value.filter((row) => row.differs) : rows.value)

/**
 * @param payload The answer of the list or the refresh.
 */
function take (payload: InstancesPayload): void {
	self.value = payload.self
	connections.value = Array.isArray(payload.instances) ? payload.instances : []
}

/**
 * Read the connections and this instance's own manifest; neither calls out.
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
 */
async function load (): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		const [list, manifest] = await Promise.all([
			ocsGet<InstancesPayload>(INSTANCES),
			ocsGet<Manifest>(MANIFEST),
		])
		if (list.error) {
			error.value = list.error
			return
		}
		take(list.payload)
		own.value = manifest.error ? null : manifest.payload
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not load the instances.')
	} finally {
		loading.value = false
	}
}

/**
 * Read every connection again; one that fails keeps its last manifest.
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
 */
async function refresh (): Promise<void> {
	refreshing.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsPost<InstancesPayload>(`${INSTANCES}/refresh`)
		if (apiError) {
			error.value = apiError
			return
		}
		take(payload)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not refresh the instances.')
	} finally {
		refreshing.value = false
	}
}

/**
 * @param connection The connection to edit, or null to add one.
 */
function openConnection (connection: InstanceConnection | null): void {
	editing.value = connection
	connectionDialogOpen.value = true
}

/**
 * Remove a connection and its stored password (password-confirmed).
 *
 * @param column The connection's column.
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
 */
async function remove (column: InstanceColumn): Promise<void> {
	if (column.id === null) {
		return
	}
	error.value = ''
	try {
		const { error: apiError } = await ocsWrite<{ id: string }>('DELETE', `${INSTANCES}/${encodeURIComponent(column.id)}`)
		if (apiError) {
			error.value = apiError
			return
		}
		connections.value = connections.value.filter((connection) => connection.id !== column.id)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not remove the connection.')
	}
}

/**
 * @param column The earlier stage.
 * @param appId The app to promote from it.
 */
function openPromote (column: InstanceColumn, appId: string): void {
	if (column.id === null) {
		return
	}
	promoteTarget.value = { appId, connection: { id: column.id, name: column.name, stage: column.stage } }
	promoteOpen.value = true
}

/**
 * @param iso A moment.
 */
function shortDate (iso: string | null): string {
	if (!iso) {
		return ''
	}
	const date = new Date(iso)
	return Number.isNaN(date.getTime())
		? ''
		: new Intl.DateTimeFormat(getCanonicalLocale(), { dateStyle: 'medium', timeZone: 'UTC' }).format(date)
}

/**
 * How old a remote column's manifest is.
 *
 * @param column The column.
 */
function age (column: InstanceColumn): string {
	const days = daysSince(column.fetchedAt)
	if (days === null) {
		return t('versioniq', 'Never read')
	}
	return days === 0
		? t('versioniq', 'Read today')
		: n('versioniq', 'Read {count} day ago', 'Read {count} days ago', days, { count: days })
}

/**
 * @param column The column.
 */
function connectionOf (column: InstanceColumn): InstanceConnection | null {
	return connections.value.find((connection) => connection.id === column.id) ?? null
}

onMounted(load)
</script>

<template>
	<section :class="$style.panel" data-testid="instances-panel">
		<div :class="$style.header">
			<h3>{{ t('versioniq', 'Instances') }}</h3>
			<div :class="$style.actions">
				<NcButton
					:disabled="refreshing || loading"
					data-testid="instances-refresh"
					@click="refresh">
					{{ refreshing ? t('versioniq', 'Refreshing…') : t('versioniq', 'Refresh') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="instances-add"
					@click="openConnection(null)">
					{{ t('versioniq', 'Add connection') }}
				</NcButton>
			</div>
		</div>
		<p :class="$style.hint">
			{{ t('versioniq', 'The app versions of every connected instance beside this one, in the order of the release chain. Versions are read from each instance\'s last answer; Refresh reads them again.') }}
		</p>

		<NcNoteCard v-if="error" type="error">
			<span data-testid="instances-error">{{ error }}</span>
		</NcNoteCard>

		<p v-if="loading" data-testid="instances-loading">
			{{ t('versioniq', 'Loading instances…') }}
		</p>

		<template v-else-if="self">
			<NcCheckboxRadioSwitch v-model="differsOnly" data-testid="instances-differs-only">
				{{ t('versioniq', 'Show only apps whose versions differ') }}
			</NcCheckboxRadioSwitch>

			<p v-if="connections.length === 0" data-testid="instances-empty">
				{{ t('versioniq', 'No other instance is connected yet. Add a connection to compare versions and promote them.') }}
			</p>

			<div :class="$style.scroll">
				<table :class="$style.table" data-testid="instances-table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('versioniq', 'App') }}
							</th>
							<th v-for="column in columns"
								:key="column.id ?? 'self'"
								scope="col"
								:data-testid="`instances-column-${column.id ?? 'self'}`">
								<span :class="$style.columnName">{{ column.name }}</span>
								<span :class="$style.stage">{{ column.isSelf ? t('versioniq', '{stage} (this instance)', { stage: column.stage }) : column.stage }}</span>
								<template v-if="!column.isSelf">
									<span :class="$style.stage" data-testid="instances-column-age">{{ age(column) }}</span>
									<span v-if="column.olderVersioniq"
										:class="$style.error"
										data-testid="instances-column-older">
										{{ t('versioniq', 'Runs an older Versioniq without instance support') }}
									</span>
									<span v-else-if="column.error"
										:class="$style.error"
										data-testid="instances-column-error">
										{{ column.error }}
									</span>
									<span :class="$style.columnActions">
										<NcButton variant="tertiary"
											:aria-label="t('versioniq', 'Edit the connection to {name}', { name: column.name })"
											data-testid="instances-edit"
											@click="openConnection(connectionOf(column))">
											{{ t('versioniq', 'Edit') }}
										</NcButton>
										<NcButton variant="tertiary"
											:aria-label="t('versioniq', 'Remove the connection to {name}', { name: column.name })"
											data-testid="instances-remove"
											@click="remove(column)">
											{{ t('versioniq', 'Remove') }}
										</NcButton>
									</span>
								</template>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in shownRows"
							:key="row.appId"
							:data-testid="`instances-row-${row.appId}`"
							:data-differs="row.differs ? 'true' : 'false'">
							<th scope="row">
								<span>{{ row.appId }}</span>
								<span v-if="row.differs" :class="$style.differs" data-testid="instances-differs">{{ t('versioniq', 'Differs') }}</span>
							</th>
							<td v-for="(cell, index) in row.cells" :key="columns[index].id ?? 'self'">
								<template v-if="!columns[index].hasManifest">
									<span :class="$style.stage">{{ t('versioniq', 'Unknown') }}</span>
								</template>
								<template v-else-if="cell && cell.installedVersion">
									<span data-testid="instances-cell-version">{{ cell.installedVersion }}</span>
									<span v-if="cell.runningSince" :class="$style.stage">{{ t('versioniq', 'since {date}', { date: shortDate(cell.runningSince) }) }}</span>
									<NcButton v-if="canPromoteFrom(row, columns, index)"
										variant="secondary"
										data-testid="instances-promote"
										@click="openPromote(columns[index], row.appId)">
										{{ t('versioniq', 'Promote from {stage}', { stage: columns[index].name }) }}
									</NcButton>
								</template>
								<span v-else :class="$style.stage">{{ t('versioniq', 'Not installed') }}</span>
							</td>
						</tr>
						<tr v-if="shownRows.length === 0">
							<td :colspan="columns.length + 1" data-testid="instances-no-rows">
								{{ differsOnly ? t('versioniq', 'Every app runs the same version on every instance.') : t('versioniq', 'No apps to show.') }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<InstanceConnectionDialog
			:open="connectionDialogOpen"
			:connection="editing"
			@update:open="(value: boolean) => (connectionDialogOpen = value)"
			@saved="load" />
		<PromoteDialog
			v-if="promoteTarget"
			:open="promoteOpen"
			:appId="promoteTarget.appId"
			:connection="promoteTarget.connection"
			@update:open="(value: boolean) => (promoteOpen = value)"
			@promoted="load"
			@rollback="(target: { appId: string, version: string }) => { promoteOpen = false; emit('rollback', target) }" />
	</section>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
}

.header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.actions,
.columnActions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.hint,
.stage {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.scroll {
	overflow-x: auto;
}

.table {
	border-collapse: collapse;
	min-width: 100%;
}

.table th,
.table td {
	padding: 8px;
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}

.table th span,
.table td span {
	display: block;
}

.columnName {
	font-weight: bold;
}

.error {
	font-size: 12px;
	color: var(--color-text-error, var(--color-error));
}

.differs {
	font-size: 12px;
	font-weight: bold;
	color: var(--color-warning-text, var(--color-warning));
}
</style>
