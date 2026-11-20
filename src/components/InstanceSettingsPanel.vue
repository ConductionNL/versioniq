<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { UpdateChecks } from './UpdateChecksPanel.vue'

import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import UpdateChecksPanel from './UpdateChecksPanel.vue'
import { ocsGet, ocsWrite } from '../ocs.ts'
import { feedCopyState, importFeedCopy } from '../utils/advisoryFeedCopy.ts'

/**
 * The instance settings that were reachable only through `occ config:app:set`
 * (#438 item 7): audit retention, artifact cache size, a store mirror, GitHub
 * Enterprise and an internal advisory feed mirror; and the update checks,
 * which GET carries under `updateChecks` and which save on their own.
 *
 * @spec openspec/specs/audit-trail/spec.md
 * @spec openspec/specs/external-sources/spec.md
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
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
	ncscFeedUrl: string
	ncscFeedDefault: string
	osvApiBase?: string
	osvApiDefault?: string
	maxLinesBehind: number | null
	maxLinesBehindMax: number
	promotionMinDays?: number
	promotionMinDaysMax?: number
	updateChecks?: UpdateChecks
	reactivateAuto?: boolean
	advisoryFeedSource?: string
	advisoryFeedCopiedAt?: number | null
	advisoryFeedCopyMaxAgeDays?: number
	allowUnsignedUpload: boolean
	notifyUsersOnUpdate?: boolean
	crlUrl?: string
	crlUrlDefault?: string
	disableRevoked?: boolean
}

const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/instance-settings'

// Sent only when the server reported them: an empty address would switch the revocation list off.
const SAFETY_FIELDS = ['crlUrl', 'disableRevoked']

const loaded = ref<Settings | null>(null)
const form = ref({
	auditRetentionDays: '',
	artifactCacheKeep: '',
	appStoreApiBase: '',
	githubApiBase: '',
	githubWebBase: '',
	advisoryFeedUrl: '',
	ncscFeedUrl: '',
	osvApiBase: '',
	maxLinesBehind: '',
	promotionMinDays: '',
	reactivateAuto: false,
	advisoryFeedSource: 'remote',
	allowUnsignedUpload: false,
	notifyUsersOnUpdate: true,
	crlUrl: '',
	disableRevoked: false,
})
const saving = ref(false)
const error = ref('')
const notice = ref('')

/**
 * A number as the form shows it, '' when it is not set.
 *
 * @param value The number, or null or undefined when it is not set.
 */
function optionalNumberText (value: number | null | undefined): string {
	return value === null || value === undefined ? '' : String(value)
}

/**
 * @param settings The settings from the server.
 * @spec openspec/specs/external-sources/spec.md
 */
function fill (settings: Settings): void {
	// PUT /api/instance-settings answers without the update checks; keep the ones loaded.
	loaded.value = { ...settings, updateChecks: settings.updateChecks ?? loaded.value?.updateChecks }
	form.value = {
		auditRetentionDays: String(settings.auditRetentionDays),
		artifactCacheKeep: String(settings.artifactCacheKeep),
		appStoreApiBase: settings.appStoreApiBase,
		githubApiBase: settings.githubApiBase,
		githubWebBase: settings.githubWebBase,
		advisoryFeedUrl: settings.advisoryFeedUrl,
		ncscFeedUrl: settings.ncscFeedUrl ?? '',
		osvApiBase: settings.osvApiBase ?? '',
		maxLinesBehind: optionalNumberText(settings.maxLinesBehind),
		promotionMinDays: String(settings.promotionMinDays ?? 0),
		reactivateAuto: settings.reactivateAuto === true,
		advisoryFeedSource: settings.advisoryFeedSource === 'local' ? 'local' : 'remote',
		allowUnsignedUpload: settings.allowUnsignedUpload === true,
		// On unless the admin switched it off (releases-notes-reach 1.2).
		notifyUsersOnUpdate: settings.notifyUsersOnUpdate !== false,
		// The address in effect; emptied, it switches fetching off (safety-withdrawn-and-revoked D1).
		crlUrl: settings.crlUrl ?? '',
		// Off unless the admin switched it on (D2).
		disableRevoked: settings.disableRevoked === true,
	}
}

const importing = ref(false)
const copyError = ref('')

/**
 * The line naming the imported copy's date, with its 30-day warning.
 *
 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
 */
const copyState = computed(() => feedCopyState(loaded.value?.advisoryFeedCopiedAt ?? null, loaded.value?.advisoryFeedCopyMaxAgeDays ?? 30, Date.now()))

/**
 * Imports the chosen feed file as the local copy; a refused file leaves the
 * previous copy in use and says why.
 *
 * @param event the file input's change
 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/security-advisory-correlation/spec.md
 */
async function importCopy (event: Event): Promise<void> {
	const input = event.target as HTMLInputElement | null
	const file = input?.files?.[0]
	if (!file || !loaded.value) {
		return
	}
	importing.value = true
	copyError.value = ''
	notice.value = ''
	const result = await importFeedCopy(file)
	importing.value = false
	if (input) {
		input.value = ''
	}
	if ('error' in result) {
		copyError.value = result.error
		return
	}
	loaded.value = { ...loaded.value, advisoryFeedCopiedAt: result.copiedAt }
	notice.value = t('versioniq', 'Advisory feed copy imported.')
}

/**
 * The waiting period's refusal, or '' when it is a whole number from 0 to the maximum.
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
 */
function waitingPeriodError (): string {
	const max = loaded.value?.promotionMinDaysMax ?? 90
	const raw = String(form.value.promotionMinDays).trim()
	if (/^\d+$/.test(raw) && Number(raw) <= max) {
		return ''
	}
	return t('versioniq', 'The waiting period must be a whole number from 0 to {max} days.', { max })
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
	notice.value = ''
	error.value = waitingPeriodError()
	if (error.value !== '') {
		return
	}
	saving.value = true
	try {
		const body = Object.fromEntries(Object.entries(form.value)
			.filter(([key]) => !SAFETY_FIELDS.includes(key) || loaded.value?.crlUrl !== undefined)
			.map(([key, value]) => [key, String(value).trim()]))
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
			<label :class="$style.field" for="setting-notify-users-on-update">
				<span>
					<input
						id="setting-notify-users-on-update"
						v-model="form.notifyUsersOnUpdate"
						type="checkbox"
						data-testid="setting-notify-users-on-update">
					{{ t('versioniq', 'Tell an app\'s users what changed after Versioniq updates it') }}
				</span>
				<span :class="$style.hint">{{ t('versioniq', 'They get the same notice as after an update from the Apps page. A dry run never sends it.') }}</span>
			</label>

			<label :class="$style.checkboxField" for="setting-reactivate-auto">
				<input
					id="setting-reactivate-auto"
					v-model="form.reactivateAuto"
					type="checkbox"
					data-testid="setting-reactivate-auto">
				<span>{{ t('versioniq', 'Enable an app again when an update left it disabled and it runs on this server') }}</span>
			</label>

			<h4>{{ t('versioniq', 'Promotion') }}</h4>
			<label :class="$style.field" for="setting-promotion-min-days">
				<span>{{ t('versioniq', 'Days a version must run on the earlier stage before it may be promoted (0 to {max}, 0 turns the check off)', { max: loaded.promotionMinDaysMax ?? 90 }) }}</span>
				<input
					id="setting-promotion-min-days"
					v-model="form.promotionMinDays"
					type="number"
					min="0"
					:max="loaded.promotionMinDaysMax ?? 90"
					data-testid="setting-promotion-min-days">
				<span :class="$style.hint">{{ t('versioniq', 'A version whose running time on the earlier stage is unknown also waits, unless the promotion is overridden.') }}</span>
			</label>

			<h4>{{ t('versioniq', 'Installing from a file') }}</h4>
			<label :class="$style.checkboxField" for="setting-allow-unsigned-upload">
				<input
					id="setting-allow-unsigned-upload"
					v-model="form.allowUnsignedUpload"
					type="checkbox"
					data-testid="setting-allow-unsigned-upload">
				<span>{{ t('versioniq', 'Allow unsigned uploads') }}</span>
			</label>
			<p :class="$style.hint">
				{{ t('versioniq', 'An archive the App Store does not sign runs whatever code it contains. Leave this off unless you install such archives and check them yourself.') }}
			</p>

			<h4>{{ t('versioniq', 'Revoked certificates') }}</h4>
			<label :class="$style.field" for="setting-crl-url">
				<span>{{ t('versioniq', 'Address of the code signing revocation list') }}</span>
				<input
					id="setting-crl-url"
					v-model="form.crlUrl"
					type="url"
					:placeholder="loaded.crlUrlDefault"
					data-testid="setting-crl-url">
				<span :class="$style.hint">{{ t('versioniq', 'Default: {url}', { url: loaded.crlUrlDefault ?? '' }) }}</span>
				<span :class="$style.hint">{{ t('versioniq', 'A newer list is used only when Nextcloud signed it. Leave the address empty to check only against the list this server shipped with.') }}</span>
			</label>
			<label :class="$style.checkboxField" for="setting-disable-revoked">
				<input
					id="setting-disable-revoked"
					v-model="form.disableRevoked"
					type="checkbox"
					data-testid="setting-disable-revoked">
				<span>{{ t('versioniq', 'Disable an app automatically when Nextcloud revokes its signing certificate') }}</span>
			</label>
			<p :class="$style.hint">
				{{ t('versioniq', 'Every admin is notified either way. The app can be enabled again on its card.') }}
			</p>

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
			<label :class="$style.field" for="setting-ncsc-feed">
				<span>{{ t('versioniq', 'NCSC-NL advisory directory (https, an internal mirror)') }}</span>
				<input
					id="setting-ncsc-feed"
					v-model="form.ncscFeedUrl"
					type="url"
					:placeholder="loaded.ncscFeedDefault"
					data-testid="setting-ncsc-feed">
				<span :class="$style.hint">{{ t('versioniq', 'Default: {url}', { url: loaded.ncscFeedDefault }) }}</span>
			</label>

			<label :class="$style.field" for="setting-advisory-feed-source">
				<span>{{ t('versioniq', 'Read advisories from') }}</span>
				<select
					id="setting-advisory-feed-source"
					v-model="form.advisoryFeedSource"
					data-testid="setting-advisory-feed-source">
					<option value="remote">
						{{ t('versioniq', 'The advisory feed address') }}
					</option>
					<option value="local">
						{{ t('versioniq', 'The local copy (no internet needed)') }}
					</option>
				</select>
			</label>
			<div :class="$style.field" data-testid="setting-advisory-feed-copy">
				<span v-if="copyState" data-testid="setting-advisory-feed-copy-state">{{ copyState.text }}</span>
				<span v-else :class="$style.hint">{{ t('versioniq', 'No local copy has been imported.') }}</span>
				<NcNoteCard v-if="copyState?.stale" type="warning">
					<span data-testid="setting-advisory-feed-copy-stale">{{ t('versioniq', 'The local copy is older than {days} days. Import a newer one.', { days: loaded.advisoryFeedCopyMaxAgeDays ?? 30 }) }}</span>
				</NcNoteCard>
				<label for="setting-advisory-feed-copy-file">
					{{ t('versioniq', 'Import a local copy of the advisory feed') }}
				</label>
				<input
					id="setting-advisory-feed-copy-file"
					type="file"
					accept="application/json,.json"
					:disabled="importing"
					data-testid="setting-advisory-feed-copy-file"
					@change="importCopy">
				<span :class="$style.hint">{{ t('versioniq', 'A file downloaded from the feed address, for an instance without internet access.') }}</span>
				<NcNoteCard v-if="copyError" type="error">
					<span data-testid="setting-advisory-feed-copy-error">{{ copyError }}</span>
				</NcNoteCard>
			</div>

			<label :class="$style.field" for="setting-osv-api">
				<span>{{ t('versioniq', 'OSV API address for the library check (https, a mirror)') }}</span>
				<input
					id="setting-osv-api"
					v-model="form.osvApiBase"
					type="url"
					:placeholder="loaded.osvApiDefault"
					data-testid="setting-osv-api">
				<span :class="$style.hint">{{ t('versioniq', 'Default: {url}', { url: loaded.osvApiDefault ?? '' }) }}</span>
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

			<UpdateChecksPanel v-if="loaded.updateChecks" :checks="loaded.updateChecks" />
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

.checkboxField {
	display: flex;
	gap: 8px;
	align-items: center;
}

.hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.notice {
	color: var(--color-success-text);
}
</style>
