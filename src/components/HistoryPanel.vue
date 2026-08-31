<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet } from '../ocs.ts'

type AuditEntry = {
	id: number
	actorUid: string
	appId: string
	operation: string
	fromVersion: string | null
	toVersion: string | null
	sourceId: string | null
	status: string
	message: string | null
	createdAt: string
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
 *
 * @param reset
 */
async function load (reset: boolean): Promise<void> {
	if (reset) {
		isLoading.value = true
		offset.value = 0
	} else {
		isLoadingMore.value = true
	}
	error.value = ''

	try {
		const query: Record<string, string | number> = {
			limit: PAGE_SIZE,
			offset: reset ? 0 : offset.value,
		}
		if (props.appId) {
			query.appId = props.appId
		}
		const { payload, error: apiError } = await ocsGet<{ entries?: AuditEntry[], limit?: number, offset?: number }>(AUDIT_ENDPOINT, query)
		if (apiError) {
			error.value = apiError
			return
		}
		const page = Array.isArray(payload.entries) ? payload.entries : []
		entries.value = reset ? page : [...entries.value, ...page]
		offset.value = (reset ? 0 : offset.value) + page.length
		hasMore.value = page.length === PAGE_SIZE
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not load the history.')
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

watch(() => props.appId, () => {
	void load(true)
}, { immediate: true })

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
						<th v-if="!appId" scope="col">
							{{ t('versioniq', 'App') }}
						</th>
						<th scope="col">{{ t('versioniq', 'Operation') }}</th>
						<th scope="col">{{ t('versioniq', 'From → to') }}</th>
						<th scope="col">{{ t('versioniq', 'Source') }}</th>
						<th scope="col">{{ t('versioniq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="entry in entries"
						:key="entry.id"
						data-testid="history-row"
						:data-operation="entry.operation"
						:data-app-id="entry.appId"
						:class="[$style.row, { [$style.rowFailure]: isFailure(entry) }]">
						<td>{{ formatWhen(entry.createdAt) }}</td>
						<td>{{ entry.actorUid }}</td>
						<td v-if="!appId">
							{{ entry.appId }}
						</td>
						<td>{{ entry.operation }}</td>
						<td :class="$style.versions">
							{{ versionTransition(entry) }}
						</td>
						<td>{{ entry.sourceId || '—' }}</td>
						<td>
							<span :class="[$style.statusBadge, { [$style.statusBadgeFailure]: isFailure(entry) }]">
								{{ entry.status }}
							</span>
							<p v-if="entry.message" :class="$style.message">
								{{ entry.message }}
							</p>
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

.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }

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
