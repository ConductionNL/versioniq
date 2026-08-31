<!--
SPDX-License-Identifier: EUPL-1.2
Pin (or re-pin, or "Accept -> move pin") an app to a specific version, with
an optional reason. Owns its own write call so App.vue only needs to open it
and react to the `pinned` event; see "Pin an installed app to its current
version" and "Honest pin presentation".
@spec openspec/specs/version-pinning/spec.md
-->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsWrite } from '../ocs.ts'

export type PinRecord = {
	appId: string
	version: string
	pinnedBy: string
	pinnedAt: string
	reason?: string | null
	driftedTo?: string | null
	driftedAt?: string | null
}

const props = defineProps<{
	open: boolean
	appId: string
	version: string
	initialReason?: string | null
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	pinned: [pin: PinRecord]
}>()

const reason = ref('')
const saving = ref(false)
const error = ref('')

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		reason.value = props.initialReason ?? ''
		error.value = ''
	}
})

/**
 *
 */
async function confirmPin (): Promise<void> {
	saving.value = true
	error.value = ''
	try {
		const body: Record<string, unknown> = { version: props.version }
		if (reason.value.trim()) {
			body.reason = reason.value.trim()
		}
		const { payload, error: apiError } = await ocsWrite<{ appId: string, pin: PinRecord }>(
			'PUT',
			`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(props.appId)}/pin`,
			body,
		)
		if (apiError) {
			error.value = apiError
			return
		}
		emit('pinned', { ...payload.pin, appId: props.appId })
		emit('update:open', false)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not pin this app.')
	} finally {
		saving.value = false
	}
}

const buttons = [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		callback: () => emit('update:open', false),
	},
	{
		label: t('versioniq', 'Pin'),
		type: 'primary' as const,
		callback: confirmPin,
	},
]
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Pin {appId} at {version}', { appId, version })"
		:buttons="buttons"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div :class="$style.body">
			<NcNoteCard type="info">
				{{ t('versioniq', 'Pins are enforced inside Versioniq and monitored elsewhere — Nextcloud\'s own updater can still update this app. If that happens you will be notified and offered a one-click re-pin.') }}
			</NcNoteCard>
			<NcTextField
				:modelValue="reason"
				:label="t('versioniq', 'Reason (optional)')"
				:placeholder="t('versioniq', 'e.g. 2.5.0 breaks LDAP sync')"
				:disabled="saving"
				@update:modelValue="(value: string) => (reason = value)" />
			<p v-if="error" :class="$style.error">
				{{ error }}
			</p>
		</div>
	</NcDialog>
</template>

<style module>
.body {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.error {
	margin: 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
