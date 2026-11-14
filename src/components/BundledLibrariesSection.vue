<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { LibraryCheck } from '../utils/bundledLibraries.ts'

import { n, t } from '@nextcloud/l10n'
import { normalizeSeverity, severityLabel } from '../utils/advisories.ts'

/**
 * One app's bundled PHP libraries as the last OSV check saw them: how many
 * were checked, each finding with its fixed version and what the admin can
 * do, or that the app ships no manifest to check.
 *
 * @spec openspec/changes/advisories-bundled-libraries
 */
defineProps<{
	appId: string
	check: LibraryCheck
}>()
</script>

<template>
	<article :id="`bundled-libraries-${appId}`" :class="$style.app" data-testid="bundled-libraries-app">
		<p :class="$style.title">
			<strong>{{ appId }}</strong>
		</p>
		<p v-if="check.manifest === null" :class="$style.hint" data-testid="bundled-libraries-no-manifest">
			{{ t('versioniq', 'No library manifest found, so its libraries were not checked.') }}
		</p>
		<template v-else>
			<p :class="$style.hint" data-testid="bundled-libraries-checked">
				{{ n('versioniq', '%n PHP library checked', '%n PHP libraries checked', check.checked) }}
			</p>
			<p v-if="check.error" :class="$style.hint" data-testid="bundled-libraries-error">
				{{ check.error }}
			</p>
			<p v-if="check.findings.length === 0" :class="$style.hint" data-testid="bundled-libraries-clean">
				{{ t('versioniq', 'No known advisory for these libraries.') }}
			</p>
			<ul v-else :class="$style.list">
				<li
					v-for="finding in check.findings"
					:key="`${finding.package}@${finding.version}:${finding.id}`"
					:class="$style.finding"
					data-testid="bundled-library-finding">
					<p :class="$style.row">
						<span
							v-if="finding.malicious"
							:class="[$style.severity, $style.malicious]"
							data-testid="bundled-library-malicious">{{ t('versioniq', 'Known malicious package') }}</span>
						<span
							v-else
							:class="[$style.severity, $style[`severity-${normalizeSeverity(finding.severity)}`]]"
							data-testid="advisory-severity"
							:data-severity="normalizeSeverity(finding.severity)">{{ severityLabel(finding.severity) }}</span>
						<code>{{ finding.package }} {{ finding.version }}</code>
						<a :href="finding.url" target="_blank" rel="noopener noreferrer">{{ finding.id }}</a>
						<span v-for="cve in finding.cveIds" :key="cve">{{ cve }}</span>
						<span v-if="!finding.malicious && finding.fixedIn" data-testid="bundled-library-fixed">
							{{ t('versioniq', 'Fixed in {version}', { version: finding.fixedIn }) }}
						</span>
					</p>
					<p v-if="finding.summary">
						{{ finding.summary }}
					</p>
					<p :class="$style.hint" data-testid="bundled-library-advice">
						<template v-if="finding.malicious">
							{{ t('versioniq', 'Remove this app or replace it with one that does not bundle {package}.', { package: finding.package }) }}
						</template>
						<template v-else-if="finding.fixedIn">
							{{ t('versioniq', 'Look for a newer version of this app that bundles {package} {version} or later, or ask its maintainer.', { package: finding.package, version: finding.fixedIn }) }}
						</template>
						<template v-else>
							{{ t('versioniq', 'No fixed version of {package} is known yet; ask the app\'s maintainer.', { package: finding.package }) }}
						</template>
					</p>
				</li>
			</ul>
		</template>
	</article>
</template>

<style module>
.app {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.title {
	display: flex;
	gap: 8px;
}

.hint {
	color: var(--color-text-maxcontrast);
}

.list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
}

.row {
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

.malicious,
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
