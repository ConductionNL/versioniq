<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { TriagedAdvisory } from '../utils/advisories.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { assigneeLabel, normalizeSeverity, severityLabel } from '../utils/advisories.ts'

/**
 * One advisory that still counts: its severity, id and summary, its owner
 * once assigned, and the Dismiss and Assign controls. An NCSC-NL record also
 * shows the NCSC-NL label, its CVE ids, NCSC-NL's own likelihood and damage
 * ratings as published, its release date and a link, and says so when
 * NCSC-NL does not state which versions are affected; a record that a
 * duplicate was folded into names the other ids (advisories-ncsc-feed D7).
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
 */
const props = defineProps<{
	advisory: TriagedAdvisory
}>()

const emit = defineEmits<{
	dismiss: []
	assign: []
}>()

const isNcsc = computed(() => props.advisory.source === 'ncsc')
const severity = computed(() => normalizeSeverity(props.advisory.severity))
const released = computed(() => (props.advisory.published ?? '').slice(0, 10))
</script>

<template>
	<li
		:class="$style.item"
		data-testid="advisory-item"
		:data-advisory="advisory.id"
		:data-source="advisory.source ?? 'nextcloud'">
		<span
			:class="[$style.severity, $style[`severity-${severity}`]]"
			data-testid="advisory-severity"
			:data-severity="severity">{{ severityLabel(advisory.severity) }}</span>
		<span v-if="isNcsc" :class="$style.source" data-testid="advisory-source">{{ t('versioniq', 'NCSC-NL') }}</span>
		<code>{{ advisory.id }}</code>
		<span>{{ advisory.summary }}</span>
		<span v-if="advisory.relatedIds && advisory.relatedIds.length > 0" data-testid="advisory-related">
			{{ t('versioniq', 'Also published as {ids}', { ids: advisory.relatedIds.join(', ') }) }}
		</span>
		<template v-if="isNcsc">
			<span v-if="advisory.cveIds && advisory.cveIds.length > 0" data-testid="advisory-cves">{{ advisory.cveIds.join(', ') }}</span>
			<span v-if="advisory.ratings" data-testid="advisory-ratings">
				{{ t('versioniq', 'NCSC-NL likelihood: {likelihood}, damage: {damage}', { likelihood: advisory.ratings.likelihood ?? '–', damage: advisory.ratings.damage ?? '–' }) }}
			</span>
			<span v-if="released">{{ t('versioniq', 'Published {date}', { date: released }) }}</span>
			<a v-if="advisory.url" :href="advisory.url" target="_blank" rel="noopener noreferrer">{{ t('versioniq', 'Read the advisory') }}</a>
			<span v-if="advisory.match === 'version_not_stated'" :class="$style.hint" data-testid="advisory-not-stated">
				{{ t('versioniq', 'NCSC-NL does not state which versions are affected.') }}
			</span>
		</template>
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

.hint,
.owner {
	color: var(--color-text-maxcontrast);
}

.actions {
	display: inline-flex;
	gap: 4px;
}

.source {
	padding: 0 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill);
	font-size: 0.9em;
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
