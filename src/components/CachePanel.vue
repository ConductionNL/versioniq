<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs.ts'

type CacheApp = { appId: string, versions: string[], sizeBytes: number }
type CacheSummary = { apps: CacheApp[], totalSizeBytes: number, keep: number }

const CACHE = '/ocs/v2.php/apps/versioniq/api/cache'

const summary = ref<CacheSummary>({ apps: [], totalSizeBytes: 0, keep: 3 })
const loading = ref(false)
const clearing = ref<string | null>(null)
const error = ref('')

const hasEntries = computed(() => summary.value.apps.length > 0)

/**
 *
 * @param bytes
 */
function formatSize (bytes: number): string {
	if (bytes <= 0) {
		return '0 B'
	}
	const units = ['B', 'KB', 'MB', 'GB']
	let value = bytes
	let unitIndex = 0
	while (value >= 1024 && unitIndex < units.length - 1) {
		value /= 1024
		unitIndex += 1
	}
	return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
}

/**
 *
 */
async function loadSummary (): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsGet<CacheSummary>(CACHE)
		if (apiError) {
			error.value = apiError
			return
		}
		summary.value = payload
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not load the artifact cache summary.')
	} finally {
		loading.value = false
	}
}

/**
 *
 * @param appId
 */
async function clearCache (appId?: string): Promise<void> {
	error.value = ''
	clearing.value = appId ?? '*'
	try {
		const path = appId ? `${CACHE}?appId=${encodeURIComponent(appId)}` : CACHE
		const { payload, error: apiError } = await ocsWrite<CacheSummary>('DELETE', path)
		if (apiError) {
			error.value = apiError
			return
		}
		summary.value = payload
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not clear the artifact cache.')
	} finally {
		clearing.value = null
	}
}

onMounted(loadSummary)
</script>

<template>
	<div :class="$style.panel">
		<h3>{{ t('versioniq', 'Release artifact cache') }}</h3>
		<p :class="$style.hint">
			{{ t('versioniq', 'Verified archives are kept locally after a successful install so rollback still works if the source deletes or moves the release. At most {keep} versions per app are retained; installs re-verify the cached artifact before use.', { keep: summary.keep }) }}
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="20" />

		<template v-else>
			<p data-testid="cache-total-size" :class="$style.total">
				{{ t('versioniq', 'Total cache size: {size}', { size: formatSize(summary.totalSizeBytes) }) }}
			</p>

			<ul :class="$style.list">
				<li v-for="app in summary.apps"
					:key="app.appId"
					data-testid="cache-app-row"
					:data-app-id="app.appId"
					:class="$style.row">
					<div :class="$style.rowInfo">
						<code>{{ app.appId }}</code>
						<span :class="$style.rowMeta">
							{{ t('versioniq', '{count} cached — {size}', { count: app.versions.length, size: formatSize(app.sizeBytes) }) }}
						</span>
					</div>
					<NcButton variant="tertiary" :disabled="clearing !== null" @click="clearCache(app.appId)">
						{{ clearing === app.appId ? t('versioniq', 'Clearing…') : t('versioniq', 'Clear') }}
					</NcButton>
				</li>
				<li v-if="!hasEntries" data-testid="cache-empty" :class="$style.empty">
					{{ t('versioniq', 'Nothing cached yet.') }}
				</li>
			</ul>

			<NcButton data-testid="cache-clear-all"
				variant="secondary"
				:disabled="!hasEntries || clearing !== null"
				@click="clearCache()">
				{{ clearing === '*' ? t('versioniq', 'Clearing…') : t('versioniq', 'Clear entire cache') }}
			</NcButton>
		</template>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; }

.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }

.total { font-weight: 600; margin: 0; }

.list { display: flex; flex-direction: column; gap: 4px; margin: 0; padding: 0; list-style: none; }

.row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--color-border); }

.rowInfo { display: flex; flex-direction: column; gap: 2px; }

.rowMeta { color: var(--color-text-maxcontrast); font-size: 12px; }

.empty { color: var(--color-text-maxcontrast); font-style: italic; }
</style>
