<!--
SPDX-License-Identifier: EUPL-1.2
Shown when the install endpoint refuses to overwrite a pinned app (HTTP 409).
Offers Re-pin (move the pin to the new version and install), Unpin (drop the
pin and install), or Cancel. The actual retry-with-override install call is
performed by the caller (App.vue already owns the full install flow); this
dialog only decides which override to send.
@spec openspec/specs/version-pinning/spec.md
-->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineProps<{
	open: boolean
	appId: string
	pinnedVersion: string
	targetVersion: string
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	resolve: [choice: 'repin' | 'unpin' | 'cancel']
}>()

/**
 *
 * @param choice
 */
function choose (choice: 'repin' | 'unpin' | 'cancel'): void {
	emit('update:open', false)
	emit('resolve', choice)
}

const buttons = [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		callback: () => choose('cancel'),
	},
	{
		label: t('versioniq', 'Unpin and install'),
		type: 'secondary' as const,
		callback: () => choose('unpin'),
	},
	{
		label: t('versioniq', 'Move pin and install'),
		type: 'primary' as const,
		callback: () => choose('repin'),
	},
]
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', '{appId} is pinned', { appId })"
		:buttons="buttons"
		@update:open="(value: boolean) => { if (!value) { choose('cancel') } }">
		<p :class="$style.text">
			{{ t('versioniq', '{appId} is pinned to version {pinnedVersion}. Versioniq will not overwrite a pin without an explicit choice.', { appId, pinnedVersion }) }}
		</p>
		<p :class="$style.text">
			{{ t('versioniq', 'Move pin and install will install {targetVersion} and move the pin there. Unpin and install will remove the pin entirely before installing.', { targetVersion }) }}
		</p>
	</NcDialog>
</template>

<style module>
.text {
	font-size: 13px;
	line-height: 1.4;
}
</style>
