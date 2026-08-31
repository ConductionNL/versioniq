<script setup lang="ts">
import { t } from '@nextcloud/l10n'
// Expandable per-version release-notes disclosure — "Per-version changelog
// display" (add-changelog-visibility). Renders notes as a plain text node
// only (mustache interpolation, never v-html), so a release body containing
// markup such as `<script>` is always inert.
//
// @spec openspec/specs/changelog-visibility/spec.md
import { computed, ref } from 'vue'

const props = defineProps<{
	version: string
	changelog: string | null
}>()

const expanded = ref(false)

const hasChangelog = computed(() => Boolean(props.changelog && props.changelog.trim() !== ''))

/**
 *
 */
function toggle (): void {
	expanded.value = !expanded.value
}
</script>

<template>
	<div :class="$style.disclosure">
		<button
			type="button"
			:class="$style.toggle"
			:aria-expanded="expanded ? 'true' : 'false'"
			:aria-controls="`changelog-body-${version}`"
			data-testid="changelog-toggle"
			@click="toggle">
			<span :class="$style.chevron" aria-hidden="true">{{ expanded ? '▾' : '▸' }}</span>
			{{ t('versioniq', 'Release notes') }}
		</button>
		<div
			v-if="expanded"
			:id="`changelog-body-${version}`"
			:class="$style.body"
			data-testid="changelog-body">
			<p v-if="hasChangelog" :class="$style.text" data-testid="changelog-text">
				{{ changelog }}
			</p>
			<p v-else :class="$style.placeholder" data-testid="changelog-placeholder">
				{{ t('versioniq', 'No release notes provided') }}
			</p>
		</div>
	</div>
</template>

<style module>
.disclosure {
	width: 100%;
}

.toggle {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: none;
	border: none;
	padding: 2px 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	cursor: pointer;
}

.toggle:hover {
	color: var(--color-main-text);
	text-decoration: underline;
}

.chevron {
	display: inline-block;
	width: 10px;
}

.body {
	margin-top: 4px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-background-hover);
}

.text {
	margin: 0;
	white-space: pre-wrap;
	/* `word-break: break-word` is deprecated. `overflow-wrap: anywhere` is its
	   behavioural equivalent: it breaks an otherwise-unbreakable run (a long
	   URL or commit hash in a changelog line) without breaking ordinary words
	   mid-character the way `word-break: break-all` would. */
	overflow-wrap: anywhere;
	font-size: 12px;
	line-height: 1.4;
}

.placeholder {
	margin: 0;
	font-size: 12px;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}
</style>
