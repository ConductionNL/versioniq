<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsGet, ocsWrite } from '../ocs.ts'
import { errorMessage } from '../utils/errorMessage.ts'

/**
 * The successor list on the Settings tab: the entries Versioniq ships and the
 * admin's own, which the admin adds and removes here (design D4). An entry is
 * offered on the old app's card only when the successor's source lists a
 * version this server can run.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
 */
export type SuccessorEntry = { from: string, to: string, source: string, custom: boolean }

const BASE = '/ocs/v2.php/apps/versioniq/api/v1/successors'

const entries = ref<SuccessorEntry[]>([])
const from = ref('')
const to = ref('')
const source = ref('appstore')
const loading = ref(false)
const error = ref('')

/**
 * Reads the list.
 */
async function load (): Promise<void> {
	error.value = ''
	try {
		const { payload } = await ocsGet<{ successors?: SuccessorEntry[] }>(BASE)
		entries.value = Array.isArray(payload?.successors) ? payload.successors : []
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'Could not load the successor list.'))
	}
}

/**
 * Sends a write and reloads the list when it succeeded.
 *
 * @param write The write to send.
 * @param fallback The message when it fails without one.
 */
async function change (write: () => ReturnType<typeof ocsWrite>, fallback: string): Promise<boolean> {
	error.value = ''
	loading.value = true
	try {
		const { error: apiError } = await write()
		if (apiError) {
			error.value = apiError
			return false
		}
		await load()
		return true
	} catch (e) {
		error.value = errorMessage(e, fallback)
		return false
	} finally {
		loading.value = false
	}
}

/**
 * Adds the entry in the form, and clears the form when it was added.
 */
async function add (): Promise<void> {
	const body = { from: from.value.trim(), to: to.value.trim(), source: source.value.trim() }
	if (await change(() => ocsWrite('POST', BASE, body), t('versioniq', 'Could not add the successor.'))) {
		from.value = ''
		to.value = ''
		source.value = 'appstore'
	}
}

/**
 * Removes one of the admin's own entries.
 *
 * @param entry The entry.
 */
async function remove (entry: SuccessorEntry): Promise<void> {
	await change(() => ocsWrite('DELETE', `${BASE}/${encodeURIComponent(entry.from)}/${encodeURIComponent(entry.to)}`), t('versioniq', 'Could not remove the successor.'))
}

onMounted(load)
</script>

<template>
	<section :class="$style.panel" data-testid="successors-panel">
		<h3>{{ t('versioniq', 'Successors') }}</h3>
		<p :class="$style.hint">
			{{ t('versioniq', 'Apps that were renamed or replaced. When the successor can be installed on this server, the old app\'s card offers to replace it. The old app\'s data is kept.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<ul :class="$style.list">
			<li v-for="entry in entries" :key="`${entry.from}>${entry.to}`" :class="$style.row" data-testid="successor-entry">
				<span>
					{{ t('versioniq', '{from} is succeeded by {to} from {source}', { from: entry.from, to: entry.to, source: entry.source }) }}
					<span v-if="!entry.custom" :class="$style.hint">{{ t('versioniq', '(shipped with Versioniq)') }}</span>
				</span>
				<NcButton v-if="entry.custom"
					variant="tertiary"
					:disabled="loading"
					data-testid="successor-remove"
					@click="remove(entry)">
					{{ t('versioniq', 'Remove') }}
				</NcButton>
			</li>
		</ul>
		<form :class="$style.form" @submit.prevent="add">
			<NcTextField v-model="from" :label="t('versioniq', 'Old app id')" />
			<NcTextField v-model="to" :label="t('versioniq', 'Successor app id')" />
			<NcTextField v-model="source" :label="t('versioniq', 'Successor source (appstore or forge:owner/repository)')" />
			<NcButton variant="primary" type="submit" :disabled="loading">
				{{ t('versioniq', 'Add successor') }}
			</NcButton>
		</form>
	</section>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; }

.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }

.list { display: flex; flex-direction: column; gap: 4px; margin: 0; padding: 0; list-style: none; }

.row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 0; border-bottom: 1px solid var(--color-border); }

.form { display: flex; flex-direction: column; gap: 8px; max-width: 480px; }
</style>
