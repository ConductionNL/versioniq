<!--
SPDX-License-Identifier: EUPL-1.2
"Install anyway" / "Enable anyway": explains what forcing a version past its
declared maximum means before anything happens. Resolves true only on the
explicit button; the caller then asks for the password and runs the install
or enable through the standard path.
@spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
-->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'

const props = defineProps<{
	open: boolean
	appId: string
	version: string
	mode: 'install' | 'enable'
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	resolve: [accept: boolean]
}>()

/**
 * Closes the dialog and settles the caller's choice.
 *
 * @param accept Whether the admin chose to force.
 */
function choose (accept: boolean): void {
	emit('update:open', false)
	emit('resolve', accept)
}

const buttons = computed(() => [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		callback: () => choose(false),
	},
	{
		label: props.mode === 'install' ? t('versioniq', 'Install anyway') : t('versioniq', 'Enable anyway'),
		variant: 'error' as const,
		callback: () => choose(true),
	},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Run {appId} {version} on a Nextcloud it does not declare', { appId, version })"
		:buttons="buttons"
		@update:open="(value: boolean) => { if (!value) { choose(false) } }">
		<p :class="$style.text" data-testid="force-warning">
			{{ t('versioniq', 'The publisher of {appId} {version} has not declared support for this Nextcloud version. Versioniq can add {appId} to Nextcloud\'s compatibility override (app_install_overwrite), so Nextcloud ignores the declared maximum. The app may not work, and it can break pages or background jobs that use it.', { appId, version }) }}
		</p>
		<p :class="$style.text">
			{{ t('versioniq', 'Versioniq lifts the override at the next major Nextcloud upgrade, and disables the app then if its version still does not declare support.') }}
		</p>
		<p :class="$style.text" data-testid="force-password-step">
			{{ t('versioniq', 'You will be asked for your password before anything changes.') }}
		</p>
	</NcDialog>
</template>

<style module>
.text {
	font-size: 13px;
	line-height: 1.4;
}
</style>
