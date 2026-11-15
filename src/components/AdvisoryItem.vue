<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { AdvisoryHistoryEntry, TriagedAdvisory } from '../utils/advisories.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { assigneeLabel, cveLabel, normalizeSeverity, publishedLabel, severityLabel } from '../utils/advisories.ts'

/**
 * One advisory: its severity, id and summary, its owner once assigned, and
 * the Dismiss and Assign controls while it still counts. Given its history
 * entry it also shows the CVE ids, the publication date, the link and
 * whether the installed version is affected.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
 */
withDefaults(defineProps<{
	advisory: TriagedAdvisory
	entry?: AdvisoryHistoryEntry | null
	readOnly?: boolean
}>(), {
	entry: null,
	readOnly: false,
})

const emit = defineEmits<{
	dismiss: []
	assign: []
}>()
</script>

<template>
	<li :class="$style.item" data-testid="advisory-item" :data-advisory="advisory.id">
		<span
			:class="[$style.severity, $style[`severity-${normalizeSeverity(advisory.severity)}`]]"
			data-testid="advisory-severity"
			:data-severity="normalizeSeverity(advisory.severity)">{{ severityLabel(advisory.severity) }}</span>
		<template v-if="entry">
			<span data-testid="advisory-cve">{{ cveLabel(entry) }}</span>
			<a v-if="entry.url"
				:href="entry.url"
				target="_blank"
				rel="noopener noreferrer"><code>{{ advisory.id }}</code></a>
			<code v-else>{{ advisory.id }}</code>
			<span data-testid="advisory-date">{{ publishedLabel(entry) }}</span>
			<span :class="{ [$style.affected]: entry.affectsInstalled }" data-testid="advisory-affects">
				{{ entry.affectsInstalled ? t('versioniq', 'Affects the installed version') : t('versioniq', 'Fixed in the installed version') }}
			</span>
		</template>
		<code v-else>{{ advisory.id }}</code>
		<span>{{ advisory.summary }}</span>
		<span v-if="advisory.assignee" :class="$style.owner" data-testid="advisory-owner">
			{{ t('versioniq', 'Owner: {owner}', { owner: assigneeLabel(advisory.assignee) }) }}
		</span>
		<span v-if="!readOnly" :class="$style.actions">
			<NcButton variant="tertiary" size="small" data-testid="advisory-dismiss" @click="emit('dismiss')">
				{{ t('versioniq', 'Dismiss advisory') }}
			</NcButton>
			<NcButton variant="tertiary" size="small" data-testid="advisory-assign" @click="emit('assign')">
				{{ t('versioniq', 'Assign') }}
			</NcButton>
		</span>
	</li>
</template>

<style module>
.item {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
}

.affected {
	color: var(--color-error-text);
	font-weight: bold;
}

.owner {
	color: var(--color-text-maxcontrast);
}

.actions {
	display: inline-flex;
	gap: 4px;
}

.severity {
	padding: 0 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill);
	font-size: 0.9em;
	font-weight: bold;
}

.severity-critical,
.severity-high {
	border-color: var(--color-error);
	color: var(--color-error-text);
}

.severity-medium {
	border-color: var(--color-warning);
	color: var(--color-warning-text);
}

.severity-low,
.severity-unknown {
	color: var(--color-text-maxcontrast);
}
</style>
