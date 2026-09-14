<script setup lang="ts">
// SPDX-License-Identifier: EUPL-1.2
// The Integrations tab: Versioniq's outside connections, read from integriq's
// connection registry (adopt-connection-registry, hydra connection-registry
// D8 and D9). Integriq works out every status; this panel only shows it.
//
// @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
import type { ConnectionRow } from '../utils/connectionRegistry.ts'

import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { apiUrl, ocsHeaders } from '../ocs.ts'
import {
	CONNECTIONS_PATH,
	connectionSettingsLabel,
	connectionStatus,
	INTEGRIQ_CONNECTIONS_PATH,
	ownConnectionRows,
} from '../utils/connectionRegistry.ts'

const rows = ref<ConnectionRow[]>([])
const isLoading = ref(false)
const hasLoadedOnce = ref(false)
const error = ref('')

const translate = (source: string): string => t('versioniq', source)

/**
 * Loads this app's connection rows from integriq's registry.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
 */
async function loadConnections (): Promise<void> {
	isLoading.value = true
	error.value = ''
	try {
		const response = await fetch(apiUrl(CONNECTIONS_PATH), {
			headers: { ...ocsHeaders, Accept: 'application/json' },
		})
		if (!response.ok) {
			error.value = response.status === 404
				? t('versioniq', 'Integriq has no connection registry on this instance yet.')
				: t('versioniq', 'Could not load the connections.')
			rows.value = []
			return
		}
		rows.value = ownConnectionRows(await response.json())
	} catch {
		error.value = t('versioniq', 'Could not load the connections.')
		rows.value = []
	} finally {
		isLoading.value = false
		hasLoadedOnce.value = true
	}
}

/**
 * A row's settings link, or '' when it has none. Only an instance-relative
 * path becomes a link, so a row can never send an admin to another host.
 *
 * @param row The connection row.
 */
function settingsHref (row: ConnectionRow): string {
	const url = row.settingsUrl
	if (typeof url !== 'string' || !/^\/[^/\\]/.test(url)) {
		return ''
	}

	return apiUrl(`/index.php${url}`)
}

/**
 * When integriq last checked the row, in the reader's locale.
 *
 * @param value The row's `checkedAt`.
 */
function formatChecked (value: unknown): string {
	if (typeof value !== 'string' || value === '') {
		return t('versioniq', 'Never')
	}
	const parsed = new Date(value)

	return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString()
}

/**
 * Opens integriq's Connections overview on the link-a-source dialog. A plain
 * navigation, because the overview is another app's page.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
 */
function addIntegration (): void {
	window.location.assign(apiUrl(INTEGRIQ_CONNECTIONS_PATH))
}

onMounted(() => {
	void loadConnections()
})
</script>

<template>
	<div :class="$style.panel" data-testid="integrations-panel">
		<div :class="$style.header">
			<h3 id="section-integrations">
				{{ t('versioniq', 'Integrations') }}
			</h3>
			<NcButton variant="secondary" data-testid="integrations-add" @click="addIntegration">
				{{ t('versioniq', 'Add integration') }}
			</NcButton>
		</div>
		<p :class="$style.hint">
			{{ t('versioniq', 'The outside systems Versioniq reads from. Integriq checks each one and shows its status here.') }}
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-if="isLoading"
			:class="$style.loading"
			role="status"
			aria-live="polite">
			<NcLoadingIcon :size="20" />
			<span>{{ t('versioniq', 'Loading connections…') }}</span>
		</p>

		<p v-else-if="hasLoadedOnce && !error && rows.length === 0" data-testid="integrations-empty" :class="$style.empty">
			{{ t('versioniq', 'Integriq has not listed the connections of Versioniq yet.') }}
		</p>

		<div v-else-if="rows.length > 0" :class="$style.tableWrapper">
			<table data-testid="integrations-table" :class="$style.table">
				<thead>
					<tr>
						<th scope="col">{{ t('versioniq', 'Connection') }}</th>
						<th scope="col">{{ t('versioniq', 'Status') }}</th>
						<th scope="col">{{ t('versioniq', 'Status message') }}</th>
						<th scope="col">{{ t('versioniq', 'Last checked') }}</th>
						<th scope="col">{{ t('versioniq', 'Settings') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows"
						:key="String(row.key)"
						data-testid="integrations-row"
						:data-key="String(row.key)"
						:data-status="String(row.status ?? '')">
						<td>{{ row.title }}</td>
						<td data-testid="integrations-status">
							{{ connectionStatus(row.status, translate) }}
						</td>
						<td>{{ row.statusMessage }}</td>
						<td>{{ formatChecked(row.checkedAt) }}</td>
						<td>
							<a v-if="settingsHref(row) !== ''"
								:href="settingsHref(row)"
								:aria-label="t('versioniq', 'Open settings for {connection}', { connection: String(row.title ?? '') })">
								{{ connectionSettingsLabel(row.settingsUrl, translate) }}
							</a>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; }

.header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }

.header h3 { margin: 0; }

.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }

.loading { display: flex; align-items: center; gap: 8px; color: var(--color-text-maxcontrast); }

.empty { color: var(--color-text-maxcontrast); font-style: italic; }

.tableWrapper { overflow-x: auto; }

.table { width: 100%; border-collapse: collapse; font-size: 13px; }

.table th { text-align: start; padding: 6px 10px; border-bottom: 2px solid var(--color-border-dark); color: var(--color-text-maxcontrast); font-weight: 600; white-space: nowrap; }

.table td { padding: 6px 10px; border-bottom: 1px solid var(--color-border); vertical-align: top; }
</style>
