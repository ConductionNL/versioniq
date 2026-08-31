<!--
SPDX-License-Identifier: EUPL-1.2
Shown when the install endpoint refuses to install because the downloaded
artifact's SHA-256 does not match the digest recorded at first install
(HTTP 422, code "sha_mismatch"). Offers an explicit, password-confirmed
"Accept new checksum and install" escape hatch or Cancel. The actual
retry-with-acceptance install call is performed by the caller (App.vue
already owns the full install flow); this dialog only decides whether to
accept.
@spec openspec/specs/external-sources/spec.md
-->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineProps<{
	open: boolean
	appId: string
	version: string
	expectedSha: string
	actualSha: string
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	resolve: [accept: boolean]
}>()

/**
 *
 * @param accept
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
		label: t('versioniq', 'Accept new checksum and install'),
		variant: 'error' as const,
		callback: () => choose(true),
	},
]
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Checksum does not match first install')"
		:buttons="buttons"
		@update:open="(value: boolean) => { if (!value) { choose(false) } }">
		<p :class="$style.text">
			{{ t('versioniq', '{appId} {version} was previously installed with a different SHA-256 checksum. The upstream release may have been rewritten since — Versioniq blocks the install to protect you from a silently altered artifact.', { appId, version }) }}
		</p>
		<dl :class="$style.shaGrid">
			<dt>{{ t('versioniq', 'Recorded at first install') }}</dt>
			<dd :class="$style.shaValue">
				{{ expectedSha }}
			</dd>
			<dt>{{ t('versioniq', 'Just downloaded') }}</dt>
			<dd :class="$style.shaValue">
				{{ actualSha }}
			</dd>
		</dl>
		<p :class="$style.text">
			{{ t('versioniq', 'Only accept if you are certain this change is legitimate — for example the maintainer re-tagged the release to fix a packaging error.') }}
		</p>
	</NcDialog>
</template>

<style module>
.text {
	font-size: 13px;
	line-height: 1.4;
}

.shaGrid {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 12px;
	margin: 12px 0;
	font-size: 12px;
}

.shaGrid dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.shaValue {
	font-family: var(--font-face, monospace);
	word-break: break-all;
}
</style>
