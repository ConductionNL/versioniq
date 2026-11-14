<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'

/**
 * Two facts the app list already carried and no card showed (#438): whether
 * the app ships with Nextcloud, and which source its versions come from.
 * A shipped app that is not always enabled looked like any other and only
 * errored once its versions were requested.
 *
 * @spec openspec/specs/version-management/spec.md
 */
export interface Provenance {
	kind: 'versioniq' | 'outside'
	sourceId: string | null
	version: string
}

/*
 * The binding is where future versions come from; the provenance is where the
 * installed one came from, which differs after a one-off install from another
 * source or a change made outside Versioniq.
 *
 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/external-sources/spec.md#requirement-the-card-names-where-the-installed-version-came-from
 */
withDefaults(defineProps<{
	isShipped: boolean
	isCore: boolean
	boundSourceId: string | null
	provenance?: Provenance | null
}>(), { provenance: null })
</script>

<template>
	<span :class="$style.badges">
		<span
			v-if="isShipped && !isCore"
			:class="$style.shipped"
			data-testid="app-shipped-badge"
			:title="t('versioniq', 'This app ships with Nextcloud: its version follows the server release. Bind a forge source to manage it from a repository.')">
			{{ t('versioniq', 'Shipped with Nextcloud') }}
		</span>
		<span :class="$style.source" data-testid="app-source">
			{{ t('versioniq', 'Source: {source}', { source: boundSourceId ?? t('versioniq', 'App Store') }) }}
		</span>
		<span
			v-if="provenance?.kind === 'versioniq'"
			:class="$style.source"
			data-testid="app-provenance">
			{{ t('versioniq', '{version} installed from {source}', { version: provenance.version, source: provenance.sourceId ?? t('versioniq', 'App Store') }) }}
		</span>
		<span
			v-else-if="provenance?.kind === 'outside'"
			:class="$style.outside"
			data-testid="app-provenance">
			{{ t('versioniq', 'Set to {version} outside Versioniq', { version: provenance.version }) }}
		</span>
	</span>
</template>

<style module>
.badges {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.shipped {
	padding: 0 6px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill);
	font-size: 12px;
}

.source {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.outside {
	font-size: 12px;
	color: var(--color-warning-text, var(--color-main-text));
}
</style>
