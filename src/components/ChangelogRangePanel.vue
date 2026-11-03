<script setup lang="ts">
import type { ChangelogRangeEntry } from '../utils/changelog.ts'

// Aggregate "changes between installed → target" panel — "Aggregate range
// changelog on target selection" (add-changelog-visibility). Receives the
// already-computed range (see utils/changelog.ts) — issues no requests of
// its own. Notes render as plain text nodes only, never v-html.
//
// @spec openspec/specs/changelog-visibility/spec.md
import { t } from '@nextcloud/l10n'

defineProps<{
	entries: ChangelogRangeEntry[]
}>()
</script>

<template>
	<div v-if="entries.length > 0" :class="$style.panel" data-testid="changelog-range-panel">
		<p :class="$style.title">
			{{ t('versioniq', 'Changes in this range') }}
		</p>
		<ul :class="$style.list">
			<li
				v-for="entry in entries"
				:key="entry.version"
				:class="$style.item"
				data-testid="changelog-range-entry">
				<span :class="$style.version">{{ entry.version }}</span>
				<p
					v-if="entry.changelog"
					:class="$style.text"
					data-testid="changelog-range-text">
					{{ entry.changelog }}
				</p>
				<p v-else :class="$style.placeholder" data-testid="changelog-range-placeholder">
					{{ t('versioniq', 'No release notes provided') }}
				</p>
			</li>
		</ul>
	</div>
</template>

<style module src="../styles/changelogText.module.css"></style>

<style module>
.panel {
	margin-top: 10px;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-background-hover);
}

.title {
	margin: 0 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.item {
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
}

.item:first-child {
	padding-top: 0;
	border-top: none;
}

.version {
	display: inline-block;
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	font-weight: 600;
	font-size: 12px;
	margin-bottom: 2px;
}

</style>
