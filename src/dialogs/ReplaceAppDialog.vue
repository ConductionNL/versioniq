<!--
SPDX-License-Identifier: EUPL-1.2
Replaces an app with its listed successor (inventory-lifecycle-signals, design
D5). States that the old app's data stays and that moving it is the
successor's job, then sends the password-confirmed replace request: the
successor is installed first and the old app disabled only after that
succeeded. A failed install is shown here and the old app stays enabled.
@spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
-->
<script setup lang="ts">
import type { SuccessorOffer } from '../utils/pendingUpdates.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsWrite } from '../ocs.ts'
import { errorMessage } from '../utils/errorMessage.ts'

const props = defineProps<{
	open: boolean
	appId: string
	offer: SuccessorOffer
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	replaced: [appId: string, successor: string]
}>()

type ReplaceOutcome = { replaced?: boolean, message?: string, install?: { message?: string } }

const busy = ref(false)
const error = ref('')

/**
 * Closes the dialog, unless a replace is in flight.
 */
function close (): void {
	if (!busy.value) {
		error.value = ''
		emit('update:open', false)
	}
}

/**
 * Sends the replace request and reports its outcome.
 */
async function replace (): Promise<void> {
	busy.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsWrite<ReplaceOutcome>('POST', `/ocs/v2.php/apps/versioniq/api/v1/app/${encodeURIComponent(props.appId)}/replace`, { to: props.offer.to })
		if (apiError || payload?.replaced !== true) {
			const reason = payload?.install?.message ?? payload?.message ?? apiError ?? ''
			error.value = t('versioniq', '{successor} could not be installed; {app} stays enabled. {reason}', { successor: props.offer.to, app: props.appId, reason })
			return
		}
		emit('replaced', props.appId, props.offer.to)
		emit('update:open', false)
	} catch (e) {
		error.value = errorMessage(e, t('versioniq', 'Could not replace the app.'))
	} finally {
		busy.value = false
	}
}

const buttons = computed(() => [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		disabled: busy.value,
		callback: () => close(),
	},
	{
		label: t('versioniq', 'Replace with {successor}', { successor: props.offer.to }),
		variant: 'primary' as const,
		disabled: busy.value,
		callback: () => {
			replace()
			return false
		},
	},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Replace {app}', { app: appId })"
		:buttons="buttons"
		@update:open="(value: boolean) => { if (!value) { close() } }">
		<p data-testid="replace-summary">
			{{ t('versioniq', 'Versioniq installs {successor} {version} from {source}, and disables {app} only after that install succeeded.', { successor: offer.to, version: offer.version, source: offer.source, app: appId }) }}
		</p>
		<p data-testid="replace-data-note">
			{{ t('versioniq', 'The data of {app} is kept. Moving it to {successor} is the successor\'s own migration.', { app: appId, successor: offer.to }) }}
		</p>
		<NcNoteCard v-if="error" type="error" data-testid="replace-error">
			{{ error }}
		</NcNoteCard>
	</NcDialog>
</template>
