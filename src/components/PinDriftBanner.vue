<!--
SPDX-License-Identifier: EUPL-1.2
Drift banner: shown when a pinned app's installed version no longer matches
its pin (NC's own updater, occ, or a manual change moved it). Offers Re-pin
(reinstall the pinned version — delegated to the parent, which owns the full
install flow with password confirmation) and Accept (move the pin to the
observed version, or remove it — both handled here directly, they are pure
pin-record writes). Never reinstalls anything itself.
@spec openspec/specs/version-pinning/spec.md
-->
<script setup lang="ts">
import type { PinRecord } from '../dialogs/PinDialog.vue'

import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsWrite } from '../ocs.ts'

const props = defineProps<{
	appId: string
	pin: PinRecord
}>()

const emit = defineEmits<{
	'update:pin': [appId: string, pin: PinRecord | null]
	repinRequested: [appId: string, version: string]
}>()

const busy = ref(false)
const error = ref('')

/**
 *
 */
function requestRepin (): void {
	emit('repinRequested', props.appId, props.pin.version)
}

/**
 *
 */
async function acceptMove (): Promise<void> {
	if (!props.pin.driftedTo) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsWrite<{ appId: string, pin: PinRecord }>(
			'PUT',
			`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(props.appId)}/pin`,
			{ version: props.pin.driftedTo },
		)
		if (apiError) {
			error.value = apiError
			return
		}
		emit('update:pin', props.appId, { ...payload.pin, appId: props.appId })
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not move the pin.')
	} finally {
		busy.value = false
	}
}

/**
 *
 */
async function acceptRemove (): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		const { error: apiError } = await ocsWrite<{ appId: string, unpinned: boolean }>(
			'DELETE',
			`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(props.appId)}/pin`,
		)
		if (apiError) {
			error.value = apiError
			return
		}
		emit('update:pin', props.appId, null)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not remove the pin.')
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<NcNoteCard type="warning" data-testid="pin-drift-banner">
		<p :class="$style.text">
			{{ t('versioniq', '{appId} is pinned to {pinnedVersion} but is now running {observedVersion} — something other than Versioniq changed it.', { appId, pinnedVersion: pin.version, observedVersion: pin.driftedTo ?? '' }) }}
		</p>
		<div :class="$style.actions">
			<NcButton variant="primary" :disabled="busy" @click="requestRepin">
				{{ t('versioniq', 'Re-pin {version}', { version: pin.version }) }}
			</NcButton>
			<NcButton variant="secondary" :disabled="busy" @click="acceptMove">
				{{ t('versioniq', 'Accept: move pin to {version}', { version: pin.driftedTo ?? '' }) }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="acceptRemove">
				{{ t('versioniq', 'Accept: remove pin') }}
			</NcButton>
		</div>
		<p v-if="error" :class="$style.error">
			{{ error }}
		</p>
	</NcNoteCard>
</template>

<style module>
.text {
	margin: 0 0 8px;
	font-size: 13px;
	line-height: 1.4;
}

.actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.error {
	margin: 8px 0 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
