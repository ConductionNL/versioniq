<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'

/**
 * Whether a version supports the running server, from `serverCompatible` on
 * the version listing: the App Store's platformVersionSpec, or for a forge
 * release its info.xml range. The API carried it and the web picker never
 * showed it (#435). Next to it, whether the version runs on the PHP version
 * this server runs (`phpCompatible`, inventory-upgrade-readiness). Unknown
 * renders nothing rather than a guess.
 *
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/changes/inventory-upgrade-readiness/specs/version-management/spec.md#requirement-versions-say-whether-they-run-on-this-servers-php
 */
withDefaults(defineProps<{
	serverCompatible: boolean | null
	phpCompatible?: boolean | null
}>(), {
	phpCompatible: null,
})
</script>

<template>
	<span
		v-if="serverCompatible !== null"
		:class="[$style.badge, serverCompatible ? $style.yes : $style.no]"
		data-testid="server-compat-badge"
		:data-compatible="serverCompatible ? 'yes' : 'no'"
		:title="serverCompatible
			? t('versioniq', 'This version declares support for the running Nextcloud version.')
			: t('versioniq', 'This version does not declare support for the running Nextcloud version; installing it will likely fail.')">
		{{ serverCompatible ? t('versioniq', 'Runs on this server') : t('versioniq', 'Not for this server version') }}
	</span>
	<span
		v-if="phpCompatible !== null"
		:class="[$style.badge, phpCompatible ? $style.yes : $style.no]"
		data-testid="php-compat-badge"
		:data-compatible="phpCompatible ? 'yes' : 'no'"
		:title="phpCompatible
			? t('versioniq', 'This version declares support for the PHP version this server runs.')
			: t('versioniq', 'This version does not declare support for the PHP version this server runs; installing it will likely fail.')">
		{{ phpCompatible ? t('versioniq', 'Runs on this PHP version') : t('versioniq', 'Not for this PHP version') }}
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
