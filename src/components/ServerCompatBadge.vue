<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * Whether a version supports the running server, from `serverCompatible` on
 * the version listing: the App Store's platformVersionSpec, or for a cached
 * forge release its info.xml range. The API carried it and the web picker
 * never showed it (#435). Unknown renders nothing rather than a guess.
 *
 * An incompatible version also says whether an override can help:
 * `serverTooNew` false means it needs a newer Nextcloud and cannot be forced.
 *
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
 */
// Without the default, Vue's boolean casting reads a left-out serverTooNew as false.
const props = withDefaults(defineProps<{
	serverCompatible: boolean | null
	serverTooNew?: boolean | null
}>(), { serverTooNew: null })

const needsNewerServer = computed(() => props.serverCompatible === false && props.serverTooNew === false)

const label = computed(() => {
	if (props.serverCompatible) {
		return t('versioniq', 'Runs on this server')
	}
	return needsNewerServer.value
		? t('versioniq', 'Needs a newer Nextcloud; cannot be forced')
		: t('versioniq', 'Not for this server version')
})

const title = computed(() => {
	if (props.serverCompatible) {
		return t('versioniq', 'This version declares support for the running Nextcloud version.')
	}
	if (needsNewerServer.value) {
		return t('versioniq', 'This version needs a newer Nextcloud than this one. An override only lifts the declared maximum, so it cannot help.')
	}
	return props.serverTooNew
		? t('versioniq', 'This version declares a maximum below the running Nextcloud version. You can install it anyway at your own risk.')
		: t('versioniq', 'This version does not declare support for the running Nextcloud version; installing it will likely fail.')
})
</script>

<template>
	<span
		v-if="serverCompatible !== null"
		:class="[$style.badge, serverCompatible ? $style.yes : $style.no]"
		data-testid="server-compat-badge"
		:data-compatible="serverCompatible ? 'yes' : 'no'"
		:title="title">
		{{ label }}
	</span>
</template>

<style module>
.badge {
	display: inline-flex;
	align-items: center;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 600;
}

.yes {
	color: var(--color-success-text);
}

.no {
	border-color: var(--color-error);
	color: var(--color-error-text);
}
</style>
