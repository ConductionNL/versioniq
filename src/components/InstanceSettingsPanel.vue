<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs.ts'

/**
 * The instance settings that were reachable only through `occ config:app:set`
 * (#438 item 7): audit retention, artifact cache size, a store mirror, GitHub
 * Enterprise and an internal advisory feed mirror.
 *
 * @spec openspec/specs/audit-trail/spec.md
 * @spec openspec/specs/external-sources/spec.md
 */
type Settings = {
	auditRetentionDays: number
	auditRetentionMinDays: number
	auditRetentionMaxDays: number
	artifactCacheKeep: number
	artifactCacheKeepMax: number
	appStoreApiBase: string
	appStoreApiDefault: string
	githubApiBase: string
	githubApiDefault: string
	githubWebBase: string
	githubWebDefault: string
	advisoryFeedUrl: string
	advisoryFeedDefault: string
	maxLinesBehind: number | null
	maxLinesBehindMax: number
}

const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/instance-settings'

const loaded = ref<Settings | null>(null)
const form = ref({
	auditRetentionDays: '',
	artifactCacheKeep: '',
	appStoreApiBase: '',
	githubApiBase: '',
	githubWebBase: '',
	advisoryFeedUrl: '',
	maxLinesBehind: '',
})
const saving = ref(false)
const error = ref('')
const notice = ref('')

/**
 * @param settings The settings from the server.
 * @spec openspec/specs/external-sources/spec.md
 */
function fill (settings: Settings): void {
	loaded.value = settings
	form.value = {
		auditRetentionDays: String(settings.auditRetentionDays),
		artifactCacheKeep: String(settings.artifactCacheKeep),
		appStoreApiBase: settings.appStoreApiBase,
		githubApiBase: settings.githubApiBase,
		githubWebBase: settings.githubWebBase,
		advisoryFeedUrl: settings.advisoryFeedUrl,
		maxLinesBehind: settings.maxLinesBehind === null || settings.maxLinesBehind === undefined ? '' : String(settings.maxLinesBehind),
	}
}

/**
 * @spec openspec/specs/external-sources/spec.md
 */
async function load (): Promise<void> {
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsGet<Settings>(ENDPOINT)
		if (apiError) {
			error.value = apiError
			return
		}
		fill(payload)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not load the settings.')
	}
}

/**
 * @spec openspec/specs/external-sources/spec.md
 */
async function save (): Promise<void> {
	saving.value = true
	error.value = ''
	notice.value = ''
	try {
		const body = Object.fromEntries(Object.entries(form.value).map(([key, value]) => [key, String(value).trim()]))
		const { payload, error: apiError } = await ocsWrite<Settings>('PUT', ENDPOINT, body)
		if (apiError) {
			error.value = apiError
			return
		}
		fill(payload)
		notice.value = t('versioniq', 'Settings saved.')
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not save the settings.')
	} finally {
		saving.value = false
	}
}

onMounted(load)
</script>

<template>
	<section :class="$style.panel" data-testid="instance-settings-panel">
		<h3>{{ t('versioniq', 'Settings') }}</h3>
		<NcNoteCard v-if="error" type="error">
			<span data-testid="instance-settings-error">{{ error }}</span>
		</NcNoteCard>

		<template v-if="loaded">
			<h4>{{ t('versioniq', 'History and cache') }}</h4>
			<label :class="$style.field" for="setting-audit-retention">
				<span>{{ t('versioniq', 'Keep history for (days, {min}-{max})', { min: loaded.auditRetentionMinDays, max: loaded.auditRetentionMaxDays }) }}</span>
				<input
					id="setting-audit-retention"
					v-model="form.auditRetentionDays"
					type="number"
					:min="loaded.auditRetentionMinDays"
					:max="loaded.auditRetentionMaxDays"
					data-testid="setting-audit-retention">
			</label>
			<label :class="$style.field" for="setting-cache-keep">
				<span>{{ t('versioniq', 'Cached versions per app (0 turns the cache off, at most {max})', { max: loaded.artifactCacheKeepMax }) }}</span>
				<input
					id="setting-cache-keep"
					v-model="form.artifactCacheKeep"
					type="number"
					min="0"
					:max="loaded.artifactCacheKeepMax"
					data-testid="setting-cache-keep">
			</label>

			<h4>{{ t('versioniq', 'Updates') }}</h4>
			<label :class="$style.field" for="setting-max-lines-behind">
				<span>{{ t('versioniq', 'Releases an app may fall behind (0 to {max}, leave empty to turn the check off)', { max: loaded.maxLinesBehindMax }) }}</span>
				<input
					id="setting-max-lines-behind"
					v-model="form.maxLinesBehind"
					type="number"
					min="0"
					:max="loaded.maxLinesBehindMax"
					data-testid="setting-max-lines-behind">
				<span :class="$style.hint">{{ t('versioniq', 'An app further behind is flagged on the Apps tab. 1 means the newest release or the one before it.') }}</span>
			</label>

			<h4>{{ t('versioniq', 'Sources and mirrors') }}</h4>
			<p :class="$style.hint">
				{{ t('versioniq', 'Leave a field empty to use the default shown under it.') }}
			</p>
			<label :class="$style.field" for="setting-appstore-api-base">
				<span>{{ t('versioniq', 'App Store API address (a store mirror)') }}</span>
				<input
					id="setting-appstore-api-base"
					v-model="form.appStoreApiBase"
					type="url"
					:placeholder="loaded.appStoreApiDefault"
					data-testid="setting-appstore-api-base">
				<span :class="$style.hint">{{ t('versioniq', 'Default: {url}', { url: loaded.appStoreApiDefault }) }}</span>
			</label>
			<label :class="$style.field" for="setting-github-api-base">
				<span>{{ t('versioniq', 'GitHub API address (GitHub Enterprise, https only)') }}</span>
				<input
					id="setting-github-api-base"
					v-model="form.githubApiBase"
					type="url"
					:placeholder="loaded.githubApiDefault"
					data-testid="setting-github-api-base">
				<span :class="$style.hint">{{ t('versioniq', 'Default: {url}', { url: loaded.githubApiDefault }) }}</span>
			</label>
			<label :class="$style.field" for="setting-github-web-base">
				<span>{{ t('versioniq', 'GitHub web address (GitHub Enterprise, https only)') }}</span>
				<input
					id="setting-github-web-base"
					v-model="form.githubWebBase"
					type="url"
					:placeholder="loaded.githubWebDefault"
					data-testid="setting-github-web-base">
				<span :class="$style.hint">{{ t('versioniq', 'Default: {url}', { url: loaded.githubWebDefault }) }}</span>
			</label>
			<label :class="$style.field" for="setting-advisory-feed">
				<span>{{ t('versioniq', 'Advisory feed address (an internal mirror)') }}</span>
				<input
					id="setting-advisory-feed"
					v-model="form.advisoryFeedUrl"
					type="url"
					:placeholder="loaded.advisoryFeedDefault"
					data-testid="setting-advisory-feed">
				<span :class="$style.hint">{{ t('versioniq', 'Default: {url}', { url: loaded.advisoryFeedDefault }) }}</span>
			</label>

			<p v-if="notice" :class="$style.notice" data-testid="instance-settings-notice">
				{{ notice }}
			</p>
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="instance-settings-save"
				@click="save">
				{{ t('versioniq', 'Save') }}
			</NcButton>
		</template>
	</section>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 720px;
	padding: 12px 0;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.notice {
	color: var(--color-success-text);
}
</style>
