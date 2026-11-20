<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type {ExportEntry} from '../utils/auditExport.ts';
import type { OriginFilter } from '../utils/auditOrigin.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { ocsGet } from '../ocs.ts'
import { collectAll, download, EXPORT_LIMIT, toCsv, toJson } from '../utils/auditExport.ts'
import { labelCarriesMessage, operationLabel } from '../utils/auditOperations.ts'
import { originFilterOptions, originLabel } from '../utils/auditOrigin.ts'
import { errorMessage } from '../utils/errorMessage.ts'
import { historyMessage } from '../utils/externalChanges.ts'
import { downloadSbom, SBOM_PATH } from '../utils/sbomDownload.ts'

type AuditEntry = {
	id: number
	actorUid: string
	appId: string
	operation: string
	/** On install rows: whether an SBOM of the state the install produced was kept (inventory-export-sbom-metrics D4). */
	sbomAvailable?: boolean
	fromVersion: string | null
	toVersion: string | null
	sourceId: string | null
	status: string
	message: string | null
	createdAt: string
	fixedAdvisories?: Array<{ id: string, severity: string }>
	/** Where the row came from; null on rows from before origins were recorded. */
	origin?: string | null
}

// Optional appId scopes this instance to a single app's history (the
// per-app "History" tab in the version picker); omitted, it is the global,
// newest-first history across every app.
const props = defineProps<{ appId?: string }>()

const PAGE_SIZE = 50
const AUDIT_ENDPOINT = '/ocs/v2.php/apps/versioniq/api/audit'

const entries = ref<AuditEntry[]>([])
const offset = ref(0)
const hasMore = ref(true)
const isLoading = ref(false)
const isLoadingMore = ref(false)
const error = ref('')
const hasLoadedOnce = ref(false)

// audit-security-reports D5: a period of whole days in the admin's time
// zone and the security updates only, with export.
const fromDate = ref('')
const toDate = ref('')
const securityOnly = ref(false)
const isExporting = ref(false)
const exportNotice = ref('')
const timeZone = (() => {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone || ''
	} catch {
		return ''
	}
})()
// The origin filter (audit-attribution-and-forwarding task 1.3): '' lists every row.
const originOptions = computed(() => originFilterOptions())
const originFilter = ref<OriginFilter>('')
const selectedOrigin = computed({
	get: () => originOptions.value.find((option) => option.id === originFilter.value) ?? originOptions.value[0],
	set: (option: { id: OriginFilter } | null) => {
		originFilter.value = option?.id ?? ''
	},
})

/**
 *
 * @param createdAt
 */
function formatWhen (createdAt: string): string {
	// Stored as UTC `Y-m-d H:i:s`; make it parseable cross-browser as ISO.
	const isoLike = createdAt.includes('T') ? createdAt : `${createdAt.replace(' ', 'T')}Z`
	const parsed = new Date(isoLike)
	return Number.isNaN(parsed.getTime()) ? createdAt : parsed.toLocaleString()
}

/**
 *
 * @param entry
 */
function versionTransition (entry: AuditEntry): string {
	const from = entry.fromVersion || '—'
	const to = entry.toVersion || '—'
	return `${from} → ${to}`
}

const isFailure = (entry: AuditEntry): boolean => entry.status !== 'success'

/**
 * Download the SBOM kept for one install row.
 *
 * @param entry the install row
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
 */
async function downloadRowSbom (entry: AuditEntry): Promise<void> {
	try {
		await downloadSbom(`${SBOM_PATH}/${entry.id}`, `nextcloud-sbom-install-${entry.id}.cdx.json`)
	} catch {
		error.value = t('versioniq', 'Could not download the SBOM of this install.')
	}
}

/**
 * Marks a first page or a next page as loading; a first page starts again at offset 0.
 *
 * @param reset whether this loads the first page
 */
function startLoading (reset: boolean): void {
	if (reset) {
		isLoading.value = true
		offset.value = 0
	} else {
		isLoadingMore.value = true
	}
	error.value = ''
}

/**
 * The audit query for the next page, scoped to the panel's app when it has one.
 *
 * @param reset whether this loads the first page
 */
function pageQuery (reset: boolean): Record<string, string | number> {
	const query: Record<string, string | number> = {
		limit: PAGE_SIZE,
		offset: reset ? 0 : offset.value,
	}
	if (props.appId) {
		query.appId = props.appId
	}
	if (originFilter.value !== '') {
		query.origin = originFilter.value
	}
	return { ...query, ...filterQuery() }
}

/**
 * The period and security filter, as audit query parameters.
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */
function filterQuery (): Record<string, string> {
	const query: Record<string, string> = {}
	if (fromDate.value) {
		query.from = fromDate.value
	}
	if (toDate.value) {
		query.to = toDate.value
	}
	if (securityOnly.value) {
		query.securityOnly = 'true'
	}
	if (Object.keys(query).length > 0 && timeZone) {
		query.timeZone = timeZone
	}
	return query
}

/**
 * Export every entry for the current filter as CSV or JSON, at most
 * EXPORT_LIMIT rows, saying so when the limit cut the list.
 *
 * @param format 'csv' or 'json'
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */
async function exportAs (format: 'csv' | 'json'): Promise<void> {
	isExporting.value = true
	exportNotice.value = ''
	error.value = ''
	try {
		const { rows, cut } = await collectAll(async (offset, limit) => {
			const query: Record<string, string | number> = { ...filterQuery(), limit, offset }
			if (props.appId) {
				query.appId = props.appId
			}
			const { payload, error: apiError } = await ocsGet<{ entries?: ExportEntry[] }>(AUDIT_ENDPOINT, query)
			if (apiError) {
				throw new Error(apiError)
			}
			return Array.isArray(payload.entries) ? payload.entries : []
		})
		const name = `versioniq-history-${fromDate.value || 'start'}-${toDate.value || 'now'}.${format}`
		if (format === 'csv') {
			download(name, 'text/csv', toCsv(rows))
		} else {
			download(name, 'application/json', toJson(rows))
		}
		if (cut) {
			exportNotice.value = t('versioniq', 'The export holds the first {limit} entries. Choose a shorter period for the rest.', { limit: EXPORT_LIMIT })
		}
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'Could not export the history.'))
	} finally {
		isExporting.value = false
	}
}

/**
 * Shows a loaded page: a first page replaces the list, a next page extends it.
 *
 * @param page the entries of the page
 * @param reset whether this is the first page
 */
function showPage (page: AuditEntry[], reset: boolean): void {
	entries.value = reset ? page : [...entries.value, ...page]
	offset.value = (reset ? 0 : offset.value) + page.length
	hasMore.value = page.length === PAGE_SIZE
}

/**
 *
 * @param reset
 */
async function load (reset: boolean): Promise<void> {
	startLoading(reset)
	try {
		const { payload, error: apiError } = await ocsGet<{ entries?: AuditEntry[], limit?: number, offset?: number }>(AUDIT_ENDPOINT, pageQuery(reset))
		if (apiError) {
			error.value = apiError
			return
		}
		showPage(Array.isArray(payload.entries) ? payload.entries : [], reset)
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'Could not load the history.'))
	} finally {
		isLoading.value = false
		isLoadingMore.value = false
		hasLoadedOnce.value = true
	}
}

/**
 *
 */
function loadMore (): void {
	void load(false)
}

watch([() => props.appId, originFilter], () => {
	void load(true)
}, { immediate: true })

watch([fromDate, toDate, securityOnly], () => {
	void load(true)
})

const isEmpty = computed(() => hasLoadedOnce.value && !isLoading.value && entries.value.length === 0 && !error.value)
</script>

<template>
	<div :class="$style.panel">
		<h3 v-if="!appId">
			{{ t('versioniq', 'History') }}
		</h3>
		<p :class="$style.hint">
			{{ appId
				? t('versioniq', 'Every install and source-binding change recorded for this app, newest first.')
				: t('versioniq', 'Every install and source-binding change Versioniq has performed, across all apps, newest first.') }}
		</p>

		<div :class="$style.filters" data-testid="history-filters">
			<NcSelect
				v-model="selectedOrigin"
				data-testid="history-origin-filter"
				:inputLabel="t('versioniq', 'Origin')"
				:options="originOptions"
				:clearable="false"
				label="label" />
			<label :class="$style.filter">
				<span>{{ t('versioniq', 'From') }}</span>
				<input v-model="fromDate" type="date" data-testid="history-from">
			</label>
			<label :class="$style.filter">
				<span>{{ t('versioniq', 'To') }}</span>
				<input v-model="toDate" type="date" data-testid="history-to">
			</label>
			<label :class="$style.filter">
				<input v-model="securityOnly" type="checkbox" data-testid="history-security-only">
				<span>{{ t('versioniq', 'Security updates only') }}</span>
			</label>
			<NcButton variant="secondary" :disabled="isExporting" data-testid="history-export-csv" @click="exportAs('csv')">
				{{ t('versioniq', 'Export CSV') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="isExporting" data-testid="history-export-json" @click="exportAs('json')">
				{{ t('versioniq', 'Export JSON') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="exportNotice" type="info" data-testid="history-export-notice">
			{{ exportNotice }}
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<p v-if="isLoading"
			:class="$style.loading"
			role="status"
			aria-live="polite">
			<NcLoadingIcon :size="20" />
			<span>{{ t('versioniq', 'Loading history…') }}</span>
		</p>

		<p v-else-if="isEmpty" data-testid="history-empty" :class="$style.empty">
			{{ t('versioniq', 'No audit entries yet.') }}
		</p>

		<div v-else-if="entries.length > 0" :class="$style.tableWrapper">
			<table data-testid="history-table" :class="$style.table">
				<thead>
					<tr>
						<!-- scope="col" on every header. Without it a screen reader has
						     to GUESS whether a <th> heads its column or its row, and in a
						     table this wide it guesses wrong — every cell is then
						     announced against the wrong header, which is worse than
						     silence because it is confidently mislabelled (WCAG 2.2 AA
						     1.3.1 Info and Relationships). -->
						<th scope="col">{{ t('versioniq', 'When') }}</th>
						<th scope="col">{{ t('versioniq', 'Who') }}</th>
						<th scope="col">{{ t('versioniq', 'Origin') }}</th>
						<th v-if="!appId" scope="col">
							{{ t('versioniq', 'App') }}
						</th>
						<th scope="col">{{ t('versioniq', 'Operation') }}</th>
						<th scope="col">{{ t('versioniq', 'From → to') }}</th>
						<th scope="col">{{ t('versioniq', 'Source') }}</th>
						<th scope="col">{{ t('versioniq', 'Status') }}</th>
						<th v-if="securityOnly" scope="col">
							{{ t('versioniq', 'Fixed advisories') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="entry in entries"
						:key="entry.id"
						data-testid="history-row"
						:data-operation="entry.operation"
						:data-app-id="entry.appId"
						:data-origin="entry.origin || 'unknown'"
						:class="[$style.row, { [$style.rowFailure]: isFailure(entry) }]">
						<td>{{ formatWhen(entry.createdAt) }}</td>
						<td>{{ entry.actorUid }}</td>
						<td data-testid="history-origin">
							{{ originLabel(entry.origin) }}
						</td>
						<td v-if="!appId">
							{{ entry.appId }}
						</td>
						<td data-testid="history-operation">
							{{ operationLabel(entry) }}
						</td>
						<td :class="$style.versions">
							{{ versionTransition(entry) }}
						</td>
						<td>{{ entry.sourceId || '—' }}</td>
						<td>
							<span :class="[$style.statusBadge, { [$style.statusBadgeFailure]: isFailure(entry) }]">
								{{ entry.status }}
							</span>
							<p v-if="entry.message && !labelCarriesMessage(entry)" :class="$style.message" data-testid="history-message">
								{{ historyMessage(entry.operation, entry.message) }}
							</p>
							<NcButton v-if="entry.sbomAvailable"
								variant="tertiary"
								data-testid="history-sbom"
								:aria-label="t('versioniq', 'Download the SBOM of this install')"
								@click="downloadRowSbom(entry)">
								{{ t('versioniq', 'SBOM') }}
							</NcButton>
						</td>
						<td v-if="securityOnly" data-testid="history-fixed">
							{{ (entry.fixedAdvisories || []).map((advisory) => advisory.id).join(', ') }}
						</td>
					</tr>
				</tbody>
			</table>

			<NcButton v-if="hasMore"
				variant="tertiary"
				:disabled="isLoadingMore"
				@click="loadMore">
				{{ isLoadingMore ? t('versioniq', 'Loading…') : t('versioniq', 'Load more') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; }

.filters { max-width: 280px; }

.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }

.filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 8px 16px; }

.filter { display: flex; align-items: center; gap: 6px; }

.loading { display: flex; align-items: center; gap: 8px; color: var(--color-text-maxcontrast); }

.empty { color: var(--color-text-maxcontrast); font-style: italic; }

.tableWrapper { overflow-x: auto; }

.table { width: 100%; border-collapse: collapse; font-size: 13px; }

.table th { text-align: start; padding: 6px 10px; border-bottom: 2px solid var(--color-border-dark); color: var(--color-text-maxcontrast); font-weight: 600; white-space: nowrap; }

.table td { padding: 6px 10px; border-bottom: 1px solid var(--color-border); vertical-align: top; }

.row:hover { background: var(--color-background-hover); }

.rowFailure { background: color-mix(in srgb, var(--color-error, #d32f2f) 8%, transparent); }

.versions { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; white-space: nowrap; }

.statusBadge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 9999px; background: var(--color-success, #2d7d46); color: var(--color-primary-text, #fff); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }

.statusBadgeFailure { background: var(--color-error, #d32f2f); }

.message { margin: 4px 0 0; color: var(--color-text-maxcontrast); font-size: 12px; }
</style>
