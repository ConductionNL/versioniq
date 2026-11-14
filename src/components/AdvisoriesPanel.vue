<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type {AdvisoryCorrelation, AdvisoryHistoryEntry, Compliance} from '../utils/advisories.ts';

import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { advisoryAnchor, advisoryHistory, complianceSentence, cveLabel, fixSentence, normalizeSeverity, publishedLabel, RECENT_ADVISORIES, severityLabel, splitAdvisoryRows } from '../utils/advisories.ts'

/**
 * Every advisory the last sweep stored, in one place: the Nextcloud server's
 * own row first (the sweep computed it but no app card could show it, #438),
 * then each app, with the severity of every advisory.
 *
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
const props = withDefaults(defineProps<{
	advisories: Record<string, AdvisoryCorrelation>
	freshnessLabel: string
	compliance?: Compliance | null
}>(), {
	compliance: null,
})

const rows = computed(() => splitAdvisoryRows(props.advisories))
const complianceLine = computed(() => complianceSentence(props.compliance))

/** The targets whose whole history is shown, not only the most recent ones. */
const expanded = ref<Record<string, boolean>>({})

/**
 * The entries of a target to render: the most recent, or all once expanded.
 *
 * @param key The target key.
 * @param row The target's correlation.
 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-each-product-lists-its-recent-advisories-with-cve-ids-and-dates
 */
function shownHistory (key: string, row: AdvisoryCorrelation): AdvisoryHistoryEntry[] {
	const history = advisoryHistory(row)
	return expanded.value[key] ? history : history.slice(0, RECENT_ADVISORIES)
}

/**
 * Shows every advisory of a target.
 *
 * @param key The target key.
 * @spec openspec/changes/advisories-per-app-detail/tasks.md#4.1
 */
function showAll (key: string): void {
	expanded.value = { ...expanded.value, [key]: true }
}
</script>

<template>
	<section :class="$style.panel" data-testid="advisories-panel">
		<h3>{{ t('versioniq', 'Security advisories') }}</h3>
		<p v-if="complianceLine"
			:class="[$style.compliance, { [$style.affected]: compliance?.status === 'not_compliant' }]"
			data-testid="advisories-compliance">
			{{ complianceLine }}
		</p>
		<p v-if="freshnessLabel" :class="$style.hint">
			{{ freshnessLabel }}
		</p>

		<div :class="$style.block" data-testid="advisories-server">
			<h4>{{ t('versioniq', 'Nextcloud server') }}</h4>
			<template v-if="rows.server">
				<p>
					{{ t('versioniq', 'Installed version: {version}', { version: rows.server.installedVersion ?? '' }) }}
				</p>
				<NcNoteCard v-if="rows.server.state === 'pinned-to-vulnerable'" type="error">
					{{ t('versioniq', 'This server version is affected by a published advisory.') }}
					<template v-if="fixSentence(rows.server, true)">
						{{ fixSentence(rows.server, true) }}
					</template>
					<template v-else-if="rows.server.recommendedVersion">
						{{ t('versioniq', 'Upgrade to {version} or later.', { version: rows.server.recommendedVersion }) }}
					</template>
				</NcNoteCard>
				<p v-else :class="$style.hint">
					{{ n('versioniq', '%n published advisory is about the server; it does not affect this version.', '%n published advisories are about the server; none affect this version.', rows.server.advisories.length) }}
				</p>
				<details v-if="rows.server.advisories.length > 0" :open="rows.server.state === 'pinned-to-vulnerable'">
					<summary>{{ t('versioniq', 'Show server advisories') }}</summary>
					<ul :class="$style.list">
						<li v-for="advisory in shownHistory(':server', rows.server)"
							:key="advisory.id"
							:class="$style.item"
							data-testid="advisory-entry">
							<span
								:class="[$style.severity, $style[`severity-${normalizeSeverity(advisory.severity)}`]]"
								data-testid="advisory-severity"
								:data-severity="normalizeSeverity(advisory.severity)">{{ severityLabel(advisory.severity) }}</span>
							<span data-testid="advisory-cve">{{ cveLabel(advisory) }}</span>
							<a v-if="advisory.url"
								:href="advisory.url"
								target="_blank"
								rel="noopener noreferrer"><code>{{ advisory.id }}</code></a>
							<code v-else>{{ advisory.id }}</code>
							<span data-testid="advisory-date">{{ publishedLabel(advisory) }}</span>
							<span :class="{ [$style.affected]: advisory.affectsInstalled }" data-testid="advisory-affects">
								{{ advisory.affectsInstalled ? t('versioniq', 'Affects the installed version') : t('versioniq', 'Fixed in the installed version') }}
							</span>
							<span>{{ advisory.summary }}</span>
						</li>
					</ul>
					<NcButton v-if="!expanded[':server'] && advisoryHistory(rows.server).length > RECENT_ADVISORIES"
						variant="tertiary"
						data-testid="advisories-show-all"
						@click="showAll(':server')">
						{{ t('versioniq', 'Show all {count}', { count: String(advisoryHistory(rows.server).length) }) }}
					</NcButton>
				</details>
			</template>
			<p v-else :class="$style.hint">
				{{ t('versioniq', 'No published advisory is about this server version.') }}
			</p>
		</div>

		<div :class="$style.block">
			<h4>{{ t('versioniq', 'Apps') }}</h4>
			<p v-if="rows.apps.length === 0" :class="$style.hint">
				{{ t('versioniq', 'No installed app has a published advisory.') }}
			</p>
			<article
				v-for="row in rows.apps"
				:id="advisoryAnchor(row.appId)"
				:key="row.appId"
				:class="$style.appRow"
				data-testid="advisories-app-row">
				<p :class="$style.appTitle">
					<strong>{{ row.appId }}</strong>
					<span v-if="row.installedVersion">{{ row.installedVersion }}</span>
					<span v-if="row.state === 'pinned-to-vulnerable'" :class="$style.affected">
						{{ t('versioniq', 'Affected') }}
					</span>
					<span v-if="row.recommendedVersion">
						{{ t('versioniq', 'safe version: {version}', { version: row.recommendedVersion }) }}
					</span>
				</p>
				<p v-if="fixSentence(row)" :class="$style.affected" data-testid="advisory-fix">
					{{ fixSentence(row) }}
				</p>
				<p v-if="row.error" :class="$style.hint">
					{{ row.error }}
				</p>
				<ul :class="$style.list">
					<li v-for="advisory in shownHistory(row.appId, row)"
						:key="advisory.id"
						:class="$style.item"
						data-testid="advisory-entry">
						<span
							:class="[$style.severity, $style[`severity-${normalizeSeverity(advisory.severity)}`]]"
							data-testid="advisory-severity"
							:data-severity="normalizeSeverity(advisory.severity)">{{ severityLabel(advisory.severity) }}</span>
						<span data-testid="advisory-cve">{{ cveLabel(advisory) }}</span>
						<a v-if="advisory.url"
							:href="advisory.url"
							target="_blank"
							rel="noopener noreferrer"><code>{{ advisory.id }}</code></a>
						<code v-else>{{ advisory.id }}</code>
						<span data-testid="advisory-date">{{ publishedLabel(advisory) }}</span>
						<span :class="{ [$style.affected]: advisory.affectsInstalled }" data-testid="advisory-affects">
							{{ advisory.affectsInstalled ? t('versioniq', 'Affects the installed version') : t('versioniq', 'Fixed in the installed version') }}
						</span>
						<span>{{ advisory.summary }}</span>
					</li>
				</ul>
				<NcButton v-if="!expanded[row.appId] && advisoryHistory(row).length > RECENT_ADVISORIES"
					variant="tertiary"
					data-testid="advisories-show-all"
					@click="showAll(row.appId)">
					{{ t('versioniq', 'Show all {count}', { count: String(advisoryHistory(row).length) }) }}
				</NcButton>
			</article>
		</div>
	</section>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 12px 0;
}

.hint {
	color: var(--color-text-maxcontrast);
}

.compliance {
	font-weight: bold;
}

.block {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.appRow {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.appTitle {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.affected {
	color: var(--color-error-text);
	font-weight: bold;
}

.list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
}

.item {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
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
