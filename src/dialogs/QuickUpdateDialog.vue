<!--
SPDX-License-Identifier: EUPL-1.2
The single confirmation behind "Update to {version}" and "Update to safe
version {version}": the app, the move, the release notes in between and an
Update button. The install itself runs through the caller's normal install
path, with every guard of the install endpoint in place.
@spec openspec/changes/install-one-click-updates/specs/version-management/spec.md#requirement-an-admin-updates-one-app-or-all-apps-in-one-action
@spec openspec/changes/install-one-click-updates/specs/security-advisory-correlation/spec.md#requirement-an-admin-updates-to-the-safe-version-straight-from-the-advisory
-->
<script setup lang="ts">
import type { ChangelogRangeEntry } from '../utils/changelog.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import ChangelogRangePanel from '../components/ChangelogRangePanel.vue'

const props = defineProps<{
	open: boolean
	appId: string
	fromVersion: string
	toVersion: string
	entries: ChangelogRangeEntry[]
	/** The advisory the target version resolves, for the safe-version button. */
	advisoryId?: string | null
	loading?: boolean
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	confirm: []
}>()

/**
 * Closes the dialog without installing.
 */
function cancel (): void {
	emit('update:open', false)
}

/**
 * Closes the dialog and asks the caller to install.
 */
function confirm (): void {
	emit('update:open', false)
	emit('confirm')
}

const buttons = computed(() => [
	{
		label: t('versioniq', 'Cancel'),
		variant: 'tertiary' as const,
		callback: cancel,
	},
	{
		label: t('versioniq', 'Update'),
		variant: 'primary' as const,
		disabled: props.loading === true,
		callback: confirm,
	},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Update {appId}', { appId })"
		:buttons="buttons"
		@update:open="(value: boolean) => { if (!value) { cancel() } }">
		<p :class="$style.text" data-testid="quick-update-move">
			{{ t('versioniq', '{appId} moves from {fromVersion} to {toVersion}.', { appId, fromVersion, toVersion }) }}
		</p>
		<p v-if="advisoryId" :class="$style.text" data-testid="quick-update-advisory">
			{{ t('versioniq', 'This version resolves advisory {advisoryId}.', { advisoryId }) }}
		</p>
		<p v-if="loading" :class="$style.text" data-testid="quick-update-loading">
			{{ t('versioniq', 'Loading release notes…') }}
		</p>
		<ChangelogRangePanel v-else :entries="entries" />
	</NcDialog>
</template>

<style module>
.text {
	font-size: 13px;
	line-height: 1.4;
}
</style>
