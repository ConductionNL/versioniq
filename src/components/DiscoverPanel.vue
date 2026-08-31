<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsGet } from '../ocs.ts'

type DiscoverySourceBinding = {
	kind?: string
	forge?: string
	owner?: string
	repo?: string
	assetPattern?: string
}

type DiscoveryCandidate = {
	providerId: string
	sourceBinding: DiscoverySourceBinding
	installable: boolean
	installableReason: string | null
}

type DiscoveryHit = {
	appId: string
	name: string
	summary: string
	iconUrl: string | null
	homepageUrl: string | null
	installedVersion: string | null
	sourceCandidates: DiscoveryCandidate[]
}

type ProviderInfo = { id: string, label: string, enabled: boolean }
type ProviderErrorEntry = { providerId: string, message: string }

type DiscoverResponsePayload = {
	results?: DiscoveryHit[]
	providers?: ProviderInfo[]
	errors?: ProviderErrorEntry[]
}

/**
 * Prefill payload emitted for a not-installed hit's installable candidate;
 * consumed by `SourcesPanel`'s optional `prefill` prop.
 */
export type PrefillBindPayload = {
	appId: string
	forge: string
	owner: string
	repo: string
	assetPattern: string
}

const emit = defineEmits<{
	(e: 'openApp', appId: string): void
	(e: 'prefillBind', payload: PrefillBindPayload): void
	(e: 'openTrusted'): void
}>()

const DISCOVER = '/ocs/v2.php/apps/versioniq/api/discover'

const MIN_QUERY_LENGTH = 2
const MAX_QUERY_LENGTH = 100
const DEBOUNCE_MS = 400

const query = ref('')
const sourceFilter = ref<string[]>([])
const installedOnly = ref(false)
const providers = ref<ProviderInfo[]>([])
const results = ref<DiscoveryHit[]>([])
const providerErrors = ref<ProviderErrorEntry[]>([])
const dismissedProviderIds = ref<Set<string>>(new Set())
const isLoading = ref(false)
const hasSearched = ref(false)
const requestError = ref('')

let debounceTimer: ReturnType<typeof setTimeout> | null = null
let abortController: AbortController | null = null

const providerOptions = computed(() => providers.value.map((provider) => ({ id: provider.id, label: provider.label })))

const visibleProviderErrors = computed(() => providerErrors.value.filter((entry) => !dismissedProviderIds.value.has(entry.providerId)))

/**
 * Client-side mirror of the API's 2–100 character bound; see "Discover tab
 * surfaces multi-source search" ("Input validation mirrors the API").
 *
 * @spec openspec/specs/app-discovery/spec.md
 */
const validationHint = computed(() => {
	const trimmedLength = query.value.trim().length
	if (trimmedLength === 0) {
		return ''
	}
	if (trimmedLength < MIN_QUERY_LENGTH) {
		return t('versioniq', 'Type at least {min} characters to search.', { min: MIN_QUERY_LENGTH })
	}
	return ''
})

const providerLabel = (providerId: string): string => providers.value.find((provider) => provider.id === providerId)?.label ?? providerId

/**
 *
 * @param providerId
 */
function dismissProviderError (providerId: string): void {
	dismissedProviderIds.value = new Set([...dismissedProviderIds.value, providerId])
}

const bestInstallableCandidate = (hit: DiscoveryHit): DiscoveryCandidate | null => hit.sourceCandidates.find((candidate) => candidate.installable) ?? null

/**
 *
 * @param hit
 */
function notInstallableReason (hit: DiscoveryHit): string {
	return hit.sourceCandidates[0]?.installableReason
		?? t('versioniq', 'No installable source was found for this app.')
}

/**
 * Cancels any in-flight request and resets to the idle/short-query state; see
 * "Input validation mirrors the API".
 *
 * @spec openspec/specs/app-discovery/spec.md
 */
function resetToIdle (): void {
	if (debounceTimer) {
		clearTimeout(debounceTimer)
		debounceTimer = null
	}
	abortController?.abort()
	abortController = null
	isLoading.value = false
	hasSearched.value = false
	results.value = []
	providerErrors.value = []
	requestError.value = ''
}

/**
 * Runs the debounced, abortable search against `GET /api/discover`; see
 * "Discover tab surfaces multi-source search" ("Search renders multi-source
 * hits") and "Partial provider failure stays usable".
 *
 * @param trimmedQuery
 * @spec openspec/specs/app-discovery/spec.md
 */
async function runSearch (trimmedQuery: string): Promise<void> {
	abortController?.abort()
	const controller = new AbortController()
	abortController = controller

	const params: Record<string, string | number | boolean> = {
		q: trimmedQuery,
		installedOnly: installedOnly.value ? '1' : '0',
	}
	if (sourceFilter.value.length > 0) {
		params.sources = sourceFilter.value.join(',')
	}

	try {
		const { payload, error } = await ocsGet<DiscoverResponsePayload>(DISCOVER, params, controller.signal)
		if (controller.signal.aborted) {
			return
		}

		if (error) {
			requestError.value = error
			results.value = []
			providerErrors.value = []
		} else {
			results.value = Array.isArray(payload.results) ? payload.results : []
			providers.value = Array.isArray(payload.providers) ? payload.providers : providers.value
			providerErrors.value = Array.isArray(payload.errors) ? payload.errors : []
			dismissedProviderIds.value = new Set()
			requestError.value = ''
		}
		hasSearched.value = true
	} catch (caught) {
		if (caught instanceof DOMException && caught.name === 'AbortError') {
			return
		}
		requestError.value = caught instanceof Error ? caught.message : t('versioniq', 'Could not search for apps.')
		results.value = []
		hasSearched.value = true
	} finally {
		if (!controller.signal.aborted) {
			isLoading.value = false
		}
	}
}

/**
 *
 */
function scheduleSearch (): void {
	if (debounceTimer) {
		clearTimeout(debounceTimer)
		debounceTimer = null
	}

	const trimmedQuery = query.value.trim()
	if (trimmedQuery.length < MIN_QUERY_LENGTH) {
		resetToIdle()
		return
	}

	requestError.value = ''
	isLoading.value = true
	debounceTimer = setTimeout(() => {
		debounceTimer = null
		void runSearch(trimmedQuery)
	}, DEBOUNCE_MS)
}

watch(query, (value) => {
	if (value.length > MAX_QUERY_LENGTH) {
		// Clamp rather than reject — mirrors the API's upper bound without
		// blocking the search the admin is already mid-typing.
		query.value = value.slice(0, MAX_QUERY_LENGTH)
		return
	}
	scheduleSearch()
})

watch([sourceFilter, installedOnly], scheduleSearch)

onBeforeUnmount(() => {
	if (debounceTimer) {
		clearTimeout(debounceTimer)
	}
	abortController?.abort()
})

/**
 *
 * @param appId
 */
function handleOpen (appId: string): void {
	emit('openApp', appId)
}

/**
 *
 * @param hit
 */
function handleInstall (hit: DiscoveryHit): void {
	const candidate = bestInstallableCandidate(hit)
	if (!candidate) {
		return
	}
	const binding = candidate.sourceBinding ?? {}
	emit('prefillBind', {
		appId: hit.appId,
		forge: binding.forge || 'github',
		owner: binding.owner || '',
		repo: binding.repo || '',
		assetPattern: binding.assetPattern || '*.tar.gz',
	})
}

/**
 *
 */
function handleOpenTrusted (): void {
	emit('openTrusted')
}
</script>

<template>
	<div :class="$style.panel">
		<h3>{{ t('versioniq', 'Discover apps') }}</h3>
		<p :class="$style.hint">
			{{ t('versioniq', 'Search the Nextcloud App Store and your configured GitHub sources for apps to install or manage.') }}
		</p>

		<div :class="$style.controls">
			<NcTextField
				v-model="query"
				:label="t('versioniq', 'Search apps')"
				:placeholder="t('versioniq', 'e.g. openregister')"
				data-testid="discover-search-input" />
			<p v-if="validationHint" :class="$style.validationHint" data-testid="discover-validation-hint">
				{{ validationHint }}
			</p>

			<NcSelect
				v-model="sourceFilter"
				:inputLabel="t('versioniq', 'Sources')"
				:options="providerOptions"
				:reduce="(option) => option.id"
				label="label"
				:multiple="true"
				:placeholder="t('versioniq', 'All sources')" />

			<NcCheckboxRadioSwitch v-model="installedOnly" data-testid="discover-installed-only">
				{{ t('versioniq', 'Installed apps only') }}
			</NcCheckboxRadioSwitch>
		</div>

		<NcNoteCard
			v-for="entry in visibleProviderErrors"
			:key="entry.providerId"
			type="warning"
			role="status"
			data-testid="discover-provider-error">
			<div :class="$style.providerErrorRow">
				<span>{{ providerLabel(entry.providerId) }}: {{ entry.message }}</span>
				<button
					type="button"
					:class="$style.dismissButton"
					data-testid="discover-dismiss-provider-error"
					@click="dismissProviderError(entry.providerId)">
					{{ t('versioniq', 'Dismiss') }}
				</button>
			</div>
		</NcNoteCard>

		<p v-if="isLoading"
			:class="$style.status"
			role="status"
			data-testid="discover-loading">
			<NcLoadingIcon :size="20" />
			<span>{{ t('versioniq', 'Searching…') }}</span>
		</p>
		<NcNoteCard v-else-if="requestError" type="error" data-testid="discover-error">
			{{ requestError }}
		</NcNoteCard>
		<p v-else-if="hasSearched && results.length === 0" :class="$style.status" data-testid="discover-empty">
			{{ t('versioniq', 'No apps matched your search.') }}
		</p>
		<ul v-else-if="hasSearched && results.length > 0" :class="$style.results" :aria-label="t('versioniq', 'Discovery results')">
			<li v-for="hit in results" :key="hit.appId">
				<article :class="$style.hitCard" :aria-label="hit.name" data-testid="discover-hit">
					<div :class="$style.hitHeader">
						<img v-if="hit.iconUrl"
							:src="hit.iconUrl"
							alt=""
							:class="$style.hitIcon">
						<div :class="$style.hitTitleBlock">
							<p :class="$style.hitName">
								{{ hit.name }}
							</p>
							<p :class="$style.hitAppId">
								{{ hit.appId }}
							</p>
						</div>
					</div>
					<p v-if="hit.summary" :class="$style.hitSummary">
						{{ hit.summary }}
					</p>
					<div :class="$style.badges">
						<span
							v-for="candidate in hit.sourceCandidates"
							:key="candidate.providerId"
							:class="[$style.sourceBadge, { [$style.sourceBadgeInstallable]: candidate.installable }]"
							data-testid="discover-source-badge">
							{{ providerLabel(candidate.providerId) }}
						</span>
					</div>
					<p v-if="hit.installedVersion" :class="$style.installedBadge" data-testid="discover-installed-version">
						{{ t('versioniq', 'Installed: {version}', { version: hit.installedVersion }) }}
					</p>

					<div :class="$style.hitActions">
						<NcButton v-if="hit.installedVersion"
							variant="primary"
							data-testid="discover-open-app"
							@click="handleOpen(hit.appId)">
							{{ t('versioniq', 'Open version picker') }}
						</NcButton>
						<NcButton v-else-if="bestInstallableCandidate(hit)"
							variant="primary"
							data-testid="discover-install"
							@click="handleInstall(hit)">
							{{ t('versioniq', 'Install…') }}
						</NcButton>
						<div v-else :class="$style.notInstallable" data-testid="discover-not-installable">
							<p>{{ notInstallableReason(hit) }}</p>
							<NcButton variant="tertiary" data-testid="discover-open-trusted" @click="handleOpenTrusted">
								{{ t('versioniq', 'Go to Trusted sources') }}
							</NcButton>
						</div>
					</div>
				</article>
			</li>
		</ul>
		<p v-else :class="$style.status" data-testid="discover-idle">
			{{ t('versioniq', 'Type an app name to search across your configured sources.') }}
		</p>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; }

.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }

.controls { display: flex; flex-direction: column; gap: 8px; max-width: 480px; }

.validationHint { margin: 0; font-size: 12px; color: var(--color-text-maxcontrast); }

.status { display: flex; align-items: center; gap: 8px; margin: 4px 0; font-size: 13px; color: var(--color-text-maxcontrast); }

.providerErrorRow { display: flex; align-items: center; justify-content: space-between; gap: 12px; }

.dismissButton {
	appearance: none;
	-webkit-appearance: none;
	background: transparent;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 2px 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	flex-shrink: 0;
}

.results { display: flex; flex-direction: column; gap: 10px; margin: 0; padding: 0; list-style: none; }

.hitCard {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	background: var(--color-main-background);
}

.hitHeader { display: flex; align-items: center; gap: 10px; }

.hitIcon { width: 32px; height: 32px; border-radius: 6px; object-fit: contain; }

.hitTitleBlock { display: flex; flex-direction: column; gap: 2px; min-width: 0; }

.hitName { margin: 0; font-weight: 700; }

.hitAppId {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.hitSummary { margin: 0; font-size: 13px; color: var(--color-text-maxcontrast); }

.badges { display: flex; flex-wrap: wrap; gap: 6px; }

.sourceBadge {
	display: inline-flex;
	align-items: center;
	padding: 2px 8px;
	border-radius: 9999px;
	background: var(--color-background-darker, #ededed);
	color: var(--color-text-maxcontrast);
	font-size: 11px;
	font-weight: 600;
}

.sourceBadgeInstallable { background: var(--color-success, #46ba61); color: var(--color-primary-text, #fff); }

.installedBadge { margin: 0; font-size: 12px; font-weight: 600; color: var(--color-text-maxcontrast); }

.hitActions { display: flex; align-items: center; gap: 8px; }

.notInstallable { display: flex; flex-direction: column; gap: 6px; }

.notInstallable p { margin: 0; font-size: 12px; color: var(--color-error-text, var(--color-error)); }
</style>
