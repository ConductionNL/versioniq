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
const props = defineProps<{
	integrityWarning: string | null
	servedFromCache: boolean
	space?: { appsFree: number | null, tempFree: number | null } | null
}>()

/**
 * A byte count in the largest unit that keeps it above one, one decimal.
 *
 * @param bytes The count.
 */
function formatBytes (bytes: number): string {
	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let value = bytes
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}

	return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`
}

/**
 * The "Environment checked" line of the progress: the free space the
 * pre-flight found and that the app folder is writable
 * (safety-rollback-backup-and-preflight D2). A folder whose free space could
 * not be read says so.
 *
 * @spec openspec/changes/safety-rollback-backup-and-preflight/specs/version-management/spec.md
 */
function environmentText (): string {
	const space = props.space
	const free = (bytes: number | null) => bytes === null ? t('versioniq', 'unknown') : formatBytes(bytes)

	return t('versioniq', 'Environment checked: {apps} free in the apps folder, {temp} free in the temp folder; the app folder is writable.', {
		apps: free(space?.appsFree ?? null),
		temp: free(space?.tempFree ?? null),
	})
}
</script>

<template>
	<div v-if="integrityWarning || servedFromCache || space" :class="$style.notices">
		<p v-if="space" :class="$style.note" data-testid="install-environment-checked">
			{{ environmentText() }}
		</p>
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
