<!--
SPDX-License-Identifier: EUPL-1.2
Asks for the password Nextcloud's own enable endpoint requires (strict
password confirmation) and hands it back; the caller performs the request.
Replaces the link to the apps page for a disabled or just-installed app (#433).
@spec openspec/specs/version-management/spec.md
-->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'

const props = defineProps<{
	open: boolean
	appId: string
	busy: boolean
	error: string
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	confirm: [password: string]
}>()

const password = ref('')
watch(() => props.open, (open) => {
	if (!open) {
		password.value = ''
	}
})

const buttons = computed(() => [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		callback: () => emit('update:open', false),
	},
	{
		label: t('versioniq', 'Enable {appId}', { appId: props.appId }),
		type: 'primary' as const,
		disabled: props.busy || password.value === '',
		callback: () => {
			emit('confirm', password.value)
			// Keep the dialog open until the caller closes it on success, so a
			// wrong password can be corrected in place.
			return false
		},
	},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Enable {appId}', { appId })"
		:buttons="buttons"
		@update:open="(value: boolean) => emit('update:open', value)">
		<p :class="$style.text">
			{{ t('versioniq', 'Nextcloud asks for your password before it enables an app.') }}
		</p>
		<NcPasswordField
			v-model="password"
			:label="t('versioniq', 'Password')"
			autocomplete="current-password" />
		<p v-if="error" :class="$style.error" data-testid="enable-app-error">{{ error }}</p>
	</NcDialog>
</template>

<style module>
.text {
	margin-bottom: 12px;
}

.error {
	margin-top: 8px;
	color: var(--color-error-text);
}
</style>
