<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type {AdvisoryCorrelation} from '../utils/advisories.ts';

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { normalizeSeverity, severityLabel, splitAdvisoryRows } from '../utils/advisories.ts'

/**
 * Every advisory the last sweep stored, in one place: the Nextcloud server's
 * own row first (the sweep computed it but no app card could show it, #438),
 * then each app, with the severity of every advisory.
 *
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
const props = defineProps<{
	advisories: Record<string, AdvisoryCorrelation>
	freshnessLabel: string
}>()

const rows = computed(() => splitAdvisoryRows(props.advisories))
</script>

<template>
	<section :class="$style.panel" data-testid="advisories-panel">
		<h3>{{ t('versioniq', 'Security advisories') }}</h3>
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
					<template v-if="rows.server.recommendedVersion">
						{{ t('versioniq', 'Upgrade to {version} or later.', { version: rows.server.recommendedVersion }) }}
					</template>
				</NcNoteCard>
				<p v-else :class="$style.hint">
					{{ n('versioniq', '%n published advisory is about the server; it does not affect this version.', '%n published advisories are about the server; none affect this version.', rows.server.advisories.length) }}
				</p>
				<details v-if="rows.server.advisories.length > 0" :open="rows.server.state === 'pinned-to-vulnerable'">
					<summary>{{ t('versioniq', 'Show server advisories') }}</summary>
					<ul :class="$style.list">
						<li v-for="advisory in rows.server.advisories" :key="advisory.id" :class="$style.item">
							<span
								:class="[$style.severity, $style[`severity-${normalizeSeverity(advisory.severity)}`]]"
								data-testid="advisory-severity"
								:data-severity="normalizeSeverity(advisory.severity)">{{ severityLabel(advisory.severity) }}</span>
							<code>{{ advisory.id }}</code>
							<span>{{ advisory.summary }}</span>
						</li>
					</ul>
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
				<p v-if="row.error" :class="$style.hint">
					{{ row.error }}
				</p>
				<ul :class="$style.list">
					<li v-for="advisory in row.advisories" :key="advisory.id" :class="$style.item">
						<span
							:class="[$style.severity, $style[`severity-${normalizeSeverity(advisory.severity)}`]]"
							data-testid="advisory-severity"
							:data-severity="normalizeSeverity(advisory.severity)">{{ severityLabel(advisory.severity) }}</span>
						<code>{{ advisory.id }}</code>
						<span>{{ advisory.summary }}</span>
					</li>
				</ul>
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
