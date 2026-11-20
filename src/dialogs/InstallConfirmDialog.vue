<!--
SPDX-License-Identifier: EUPL-1.2
Asks once before an install that a rule would hold back, and lets the admin go
ahead: a version outside a policy that holds manual installs to its level
(the override is audited), or a version an admin skipped. The caller performs
the install; this dialog only answers yes or no.
@spec openspec/changes/pinning-ranges-and-skips/specs/auto-update-policies/spec.md#requirement-a-policy-can-hold-manual-installs-to-its-level
@spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
-->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineProps<{
	open: boolean
	title: string
	message: string
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	resolve: [accept: boolean]
}>()

/**
 * Close and answer.
 *
 * @param accept Whether to install anyway.
 */
function choose (accept: boolean): void {
	emit('update:open', false)
	emit('resolve', accept)
}

const buttons = [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		callback: () => choose(false),
	},
	{
		label: t('versioniq', 'Install anyway'),
		type: 'primary' as const,
		callback: () => choose(true),
	},
]
</script>

<template>
	<NcDialog
		:open="open"
		:name="title"
		:buttons="buttons"
		data-testid="install-confirm-dialog"
		@update:open="(value: boolean) => { if (!value) { choose(false) } }">
		<p :class="$style.text">
			{{ message }}
		</p>
	</NcDialog>
</template>

<style module>
.text {
	font-size: 13px;
	line-height: 1.4;
}
</style>
