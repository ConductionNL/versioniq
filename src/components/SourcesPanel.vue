<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsGet, ocsWrite } from '../ocs.ts'
import { FORGE_FORGEJO, forgeOf, isRetiredForge, forgeOptions as offeredForges } from '../utils/forges.ts'

type AppOption = { id: string, label: string }
type SelectOption = { id: string, label: string }

/**
 * Prefill payload for the bind form — set by the Discover tab when an admin
 * activates a not-installed hit's installable source candidate; see "Hits
 * route into existing flows" ("Installable candidate prefills bind").
 */
type BindPrefill = {
	appId: string
	forge?: string
	owner?: string
	repo?: string
	assetPattern?: string
}

const props = defineProps<{ apps: AppOption[], prefill?: BindPrefill | null }>()
const emit = defineEmits<{ (e: 'bound', appId: string): void }>()

const selectedAppId = ref('')
const currentBinding = ref<{ sourceId?: string } | null>(null)
const forge = ref('github')
const owner = ref('')
const repo = ref('')
const assetPattern = ref('*.tar.gz')
const loading = ref(false)
const error = ref('')
const notice = ref('')

/**
 * Applies a Discover-tab prefill into the bind form; optional and additive —
 * SourcesPanel behaves exactly as before when no prefill is supplied.
 *
 * @spec openspec/specs/app-discovery/spec.md
 */
watch(() => props.prefill, (value) => {
	if (!value) {
		return
	}
	selectedAppId.value = value.appId
	if (value.forge) {
		forge.value = value.forge
	}
	if (value.owner) {
		owner.value = value.owner
	}
	if (value.repo) {
		repo.value = value.repo
	}
	if (value.assetPattern) {
		assetPattern.value = value.assetPattern
	}
}, { immediate: true })

const appOptions = computed<SelectOption[]>(() => props.apps.map((app) => ({ id: app.id, label: `${app.label} (${app.id})` })))
const forgeOptions: SelectOption[] = offeredForges()

const FORGEJO_HOST = '/ocs/v2.php/apps/versioniq/api/forges/forgejo'
const forgejoHost = ref('')
const forgejoConfigured = ref(false)
const hostNotice = ref('')
const hostError = ref('')

/**
 * Whether the selected app is still bound to a retired forge (Codeberg);
 * such a binding keeps working but should be rebound (issue #437).
 */
const boundToRetiredForge = computed(() => isRetiredForge(forgeOf(currentBinding.value?.sourceId ?? '')))

/**
 * Loads the self-hosted Forgejo or Gitea host.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
async function loadForgejoHost (): Promise<void> {
	try {
		const { payload } = await ocsGet<{ host?: string, configured?: boolean }>(FORGEJO_HOST)
		forgejoHost.value = payload.host ?? ''
		forgejoConfigured.value = payload.configured === true
	} catch (e) {
		hostError.value = e instanceof Error ? e.message : t('versioniq', 'Could not load the self-hosted forge host.')
	}
}

/**
 * Saves (or, when empty, clears) the self-hosted Forgejo or Gitea host.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
async function saveForgejoHost (): Promise<void> {
	hostError.value = ''
	hostNotice.value = ''
	loading.value = true
	try {
		const { payload, error: apiError } = await ocsWrite<{ host?: string, configured?: boolean }>(
			'PUT',
			FORGEJO_HOST,
			{ host: forgejoHost.value.trim() },
		)
		if (apiError) {
			hostError.value = apiError
			return
		}
		forgejoHost.value = payload.host ?? ''
		forgejoConfigured.value = payload.configured === true
		hostNotice.value = forgejoConfigured.value
			? t('versioniq', 'Self-hosted forge host saved.')
			: t('versioniq', 'Self-hosted forge host cleared.')
	} catch (e) {
		hostError.value = e instanceof Error ? e.message : t('versioniq', 'Could not save the self-hosted forge host.')
	} finally {
		loading.value = false
	}
}

onMounted(loadForgejoHost)

/**
 *
 * @param appId
 */
async function loadBinding (appId: string): Promise<void> {
	currentBinding.value = null
	if (!appId) {
		return
	}
	try {
		const { payload } = await ocsGet<{ sourceId?: string, binding?: unknown }>(
			`/ocs/v2.php/apps/versioniq/api/source/${encodeURIComponent(appId)}/binding`,
		)
		currentBinding.value = { sourceId: payload.sourceId }
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not load the current binding.')
	}
}

watch(selectedAppId, (appId) => {
	error.value = ''
	notice.value = ''
	void loadBinding(appId)
})

/**
 *
 */
async function bind (): Promise<void> {
	error.value = ''
	notice.value = ''
	if (!selectedAppId.value) {
		error.value = t('versioniq', 'Select an app first.')
		return
	}
	if (!owner.value.trim() || !repo.value.trim()) {
		error.value = t('versioniq', 'Owner and repository are required.')
		return
	}
	if (forge.value === FORGE_FORGEJO && !forgejoConfigured.value) {
		error.value = t('versioniq', 'Set the self-hosted Forgejo or Gitea host below first.')
		return
	}
	loading.value = true
	try {
		const { payload, error: apiError } = await ocsWrite<{ sourceId?: string }>(
			'POST',
			`/ocs/v2.php/apps/versioniq/api/source/${encodeURIComponent(selectedAppId.value)}/bind`,
			{
				kind: 'github-release',
				forge: forge.value,
				owner: owner.value.trim(),
				repo: repo.value.trim(),
				assetPattern: assetPattern.value.trim() || '*.tar.gz',
			},
		)
		if (apiError) {
			error.value = apiError
			return
		}
		currentBinding.value = { sourceId: payload.sourceId }
		notice.value = t('versioniq', 'Bound to {source}', { source: payload.sourceId ?? '' })
		emit('bound', selectedAppId.value)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not bind the source.')
	} finally {
		loading.value = false
	}
}
</script>

<template>
	<div :class="$style.panel">
		<h3 id="section-sources">{{ t('versioniq', 'App sources') }}</h3>
		<p :class="$style.hint">
			{{ t('versioniq', 'Bind an installed app to a GitHub repository, or to one on a self-hosted Forgejo or Gitea host, so its versions are pulled from that forge instead of the App Store. The repository must be on the trusted-sources list.') }}
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">
			{{ notice }}
		</NcNoteCard>

		<form :class="$style.form" @submit.prevent="bind">
			<NcSelect
				v-model="selectedAppId"
				:inputLabel="t('versioniq', 'App')"
				:options="appOptions"
				:reduce="(option) => option.id"
				:placeholder="t('versioniq', 'Choose an app')"
				label="label" />

			<p v-if="currentBinding && currentBinding.sourceId" :class="$style.hint">
				{{ t('versioniq', 'Current source:') }} <code>{{ currentBinding.sourceId }}</code>
			</p>
			<NcNoteCard v-if="boundToRetiredForge" type="warning" data-testid="retired-source">
				{{ t('versioniq', 'This app is bound to Codeberg, which is retired as a separate source. The binding keeps working for now; rebind it to GitHub or to a self-hosted Forgejo or Gitea host.') }}
			</NcNoteCard>

			<NcSelect
				v-model="forge"
				:inputLabel="t('versioniq', 'Forge')"
				:options="forgeOptions"
				:reduce="(option) => option.id"
				:clearable="false"
				label="label" />
			<NcTextField v-model="owner" :label="t('versioniq', 'Owner')" placeholder="ConductionNL" />
			<NcTextField v-model="repo" :label="t('versioniq', 'Repository')" placeholder="openregister" />
			<NcTextField v-model="assetPattern" :label="t('versioniq', 'Asset pattern')" placeholder="*.tar.gz" />
			<NcButton variant="primary" type="submit" :disabled="loading">
				{{ t('versioniq', 'Bind source') }}
			</NcButton>
		</form>

		<h3 id="section-forgejo-host">{{ t('versioniq', 'Self-hosted Forgejo or Gitea') }}</h3>
		<p :class="$style.hint">
			{{ t('versioniq', 'Set the address of your Forgejo or Gitea host to bind apps, store tokens and trust owners on it. Leave it empty to turn the forge off.') }}
		</p>
		<NcNoteCard v-if="hostError" type="error">
			{{ hostError }}
		</NcNoteCard>
		<NcNoteCard v-if="hostNotice" type="success">
			{{ hostNotice }}
		</NcNoteCard>
		<form :class="$style.form" @submit.prevent="saveForgejoHost">
			<NcTextField v-model="forgejoHost" :label="t('versioniq', 'Self-hosted Forgejo or Gitea host')" placeholder="https://git.example.org" />
			<NcButton variant="secondary" type="submit" :disabled="loading">
				{{ t('versioniq', 'Save host') }}
			</NcButton>
		</form>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; }

.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }

.form { display: flex; flex-direction: column; gap: 8px; max-width: 480px; }
</style>
