<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'

/**
 * What the installer said about how it got the artifact: an install that went
 * ahead without checksum verification, and one served from the local cache.
 * Both were in the install payload and never shown (#438).
 *
 * @spec openspec/specs/external-sources/spec.md
 */
defineProps<{
	integrityWarning: string | null
	servedFromCache: boolean
}>()
</script>

<template>
	<div v-if="integrityWarning || servedFromCache" :class="$style.notices">
		<p
			v-if="integrityWarning"
			:class="$style.warning"
			role="alert"
			data-testid="install-integrity-warning">
			<strong>{{ t('versioniq', 'Installed without checksum verification.') }}</strong>
			{{ integrityWarning }}
		</p>
		<p v-if="servedFromCache" :class="$style.note" data-testid="install-served-from-cache">
			{{ t('versioniq', 'The package came from the local artifact cache, not from the source.') }}
		</p>
	</div>
</template>

<style module>
.notices {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 8px 0;
}

.warning {
	padding: 8px 12px;
	border-inline-start: 4px solid var(--color-warning);
	background: var(--color-warning-hover, var(--color-background-hover));
	color: var(--color-main-text);
}

.note {
	color: var(--color-text-maxcontrast);
}
</style>
