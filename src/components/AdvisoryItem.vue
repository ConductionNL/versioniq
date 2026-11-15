<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { TriagedAdvisory } from '../utils/advisories.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { assigneeLabel, normalizeSeverity, severityLabel } from '../utils/advisories.ts'

/**
 * One advisory that still counts: its severity, id and summary, its owner
 * once assigned, and the Dismiss and Assign controls.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 */
defineProps<{
	advisory: TriagedAdvisory
}>()

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
		<code>{{ advisory.id }}</code>
		<span>{{ advisory.summary }}</span>
		<span v-if="advisory.assignee" :class="$style.owner" data-testid="advisory-owner">
			{{ t('versioniq', 'Owner: {owner}', { owner: assigneeLabel(advisory.assignee) }) }}
		</span>
		<span :class="$style.actions">
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
