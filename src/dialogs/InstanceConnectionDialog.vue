<!--
SPDX-License-Identifier: EUPL-1.2
Add or change a connection to another instance that runs Versioniq
(install-multi-instance-promotion D1, D2). The app password is sent once and
never shown again: on an edit an empty password keeps the stored one. The
server reads the remote manifest on save, so the table shows the result.
@spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
-->
<script setup lang="ts">
import type { InstanceConnection } from '../utils/instanceMatrix.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsWrite } from '../ocs.ts'

const props = defineProps<{
	open: boolean
	connection: InstanceConnection | null
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	saved: [connection: InstanceConnection]
}>()

const ENDPOINT = '/ocs/v2.php/apps/versioniq/api/instances'

const form = ref({ id: '', name: '', stage: '', order: '0', baseUrl: '', username: '', password: '' })
const saving = ref(false)
const error = ref('')

const editing = computed((): boolean => props.connection !== null)

watch(() => props.open, (isOpen) => {
	if (!isOpen) {
		return
	}
	const connection = props.connection
	form.value = {
		id: connection?.id ?? '',
		name: connection?.name ?? '',
		stage: connection?.stage ?? '',
		order: String(connection?.order ?? 0),
		baseUrl: connection?.baseUrl ?? '',
		username: connection?.username ?? '',
		password: '',
	}
	error.value = ''
}, { immediate: true })

/**
 * Save the connection (password-confirmed); the server refuses an http
 * address or a duplicate id with a message shown here.
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
 */
async function save (): Promise<void> {
	saving.value = true
	error.value = ''
	try {
		const body = {
			id: form.value.id.trim(),
			name: form.value.name.trim(),
			stage: form.value.stage.trim(),
			order: Number.parseInt(form.value.order, 10) || 0,
			baseUrl: form.value.baseUrl.trim(),
			username: form.value.username.trim(),
			password: form.value.password,
		}
		const path = editing.value ? `${ENDPOINT}/${encodeURIComponent(body.id)}` : ENDPOINT
		const { payload, error: apiError } = await ocsWrite<InstanceConnection & { message?: string }>(editing.value ? 'PUT' : 'POST', path, body)
		if (apiError) {
			error.value = payload?.message || apiError
			return
		}
		emit('saved', payload)
		emit('update:open', false)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not save the connection.')
	} finally {
		saving.value = false
	}
}

const buttons = computed(() => [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		callback: () => emit('update:open', false),
	},
	{
		label: t('versioniq', 'Save connection'),
		type: 'primary' as const,
		disabled: saving.value,
		callback: save,
	},
])

/**
 * @param field The form field.
 * @param value Its new value.
 */
function set (field: keyof typeof form.value, value: string): void {
	form.value = { ...form.value, [field]: value }
}
</script>

<template>
	<NcDialog
		:open="open"
		:name="editing ? t('versioniq', 'Edit the connection to {name}', { name: connection?.name ?? '' }) : t('versioniq', 'Add connection')"
		:buttons="buttons"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div :class="$style.body" data-testid="instance-connection-dialog">
			<NcNoteCard type="info">
				{{ t('versioniq', 'Use a dedicated admin account on that instance and an app password for it. The password is stored encrypted and is only used to read that instance\'s app versions.') }}
			</NcNoteCard>
			<NcTextField v-if="!editing"
				:modelValue="form.id"
				:label="t('versioniq', 'Connection id (lowercase letters, digits and hyphens)')"
				:disabled="saving"
				data-testid="instance-connection-id"
				@update:modelValue="(value: string) => set('id', value)" />
			<NcTextField
				:modelValue="form.name"
				:label="t('versioniq', 'Name')"
				:disabled="saving"
				data-testid="instance-connection-name"
				@update:modelValue="(value: string) => set('name', value)" />
			<NcTextField
				:modelValue="form.stage"
				:label="t('versioniq', 'Stage (such as test, acceptance or production)')"
				:disabled="saving"
				data-testid="instance-connection-stage"
				@update:modelValue="(value: string) => set('stage', value)" />
			<NcTextField
				:modelValue="form.order"
				type="number"
				:label="t('versioniq', 'Place in the release chain (0 to 99, lower comes first)')"
				:disabled="saving"
				data-testid="instance-connection-order"
				@update:modelValue="(value: string) => set('order', value)" />
			<NcTextField
				:modelValue="form.baseUrl"
				type="url"
				:label="t('versioniq', 'Address (https only)')"
				:disabled="saving"
				data-testid="instance-connection-url"
				@update:modelValue="(value: string) => set('baseUrl', value)" />
			<NcTextField
				:modelValue="form.username"
				:label="t('versioniq', 'Admin account on that instance')"
				:disabled="saving"
				data-testid="instance-connection-username"
				@update:modelValue="(value: string) => set('username', value)" />
			<NcTextField
				:modelValue="form.password"
				type="password"
				:label="editing ? t('versioniq', 'App password (leave empty to keep the stored one)') : t('versioniq', 'App password')"
				:disabled="saving"
				data-testid="instance-connection-password"
				@update:modelValue="(value: string) => set('password', value)" />
			<p v-if="error" :class="$style.error" data-testid="instance-connection-error">
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
