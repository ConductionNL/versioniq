<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * Who publishes an app and the trust marks a named source backs, on a
 * Discover hit and on an app card. Each mark's tooltip says who made the
 * claim; nothing here calls an app safe. A similar-name hint names the other
 * publisher so two look-alike apps can be told apart; it never blocks.
 *
 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md
 */
export type PublisherSimilar = {
	appId: string
	name: string
	publisher: string | null
}

const props = withDefaults(defineProps<{
	publisher: string | null
	marks?: string[]
	similarTo?: PublisherSimilar[]
}>(), {
	marks: () => [],
	similarTo: () => [],
})

type MarkView = { id: string, label: string, title: string }

const MARK_ORDER = ['shipped', 'featured', 'verifiedOrg', 'trusted']

/**
 *
 * @param id
 */
function markView (id: string): MarkView | null {
	switch (id) {
	case 'shipped':
		return { id, label: t('versioniq', 'Ships with Nextcloud'), title: t('versioniq', 'The Nextcloud server ships this app.') }
	case 'featured':
		return { id, label: t('versioniq', 'Featured in the App Store'), title: t('versioniq', 'The App Store marks this app as featured.') }
	case 'verifiedOrg':
		return { id, label: t('versioniq', 'Verified organisation'), title: t('versioniq', 'GitHub has verified the domain of this organisation.') }
	case 'trusted':
		return { id, label: t('versioniq', 'On your trusted list'), title: t('versioniq', 'An administrator put this source on the trusted list.') }
	default:
		return null
	}
}

const shownMarks = computed((): MarkView[] => MARK_ORDER
	.filter((id) => props.marks.includes(id))
	.map(markView)
	.filter((mark): mark is MarkView => mark !== null))

const publisherLabel = computed((): string => {
	const name = (props.publisher ?? '').trim()
	return name === '' ? t('versioniq', 'Publisher unknown') : t('versioniq', 'By {publisher}', { publisher: name })
})

/**
 *
 * @param similar
 */
function similarLabel (similar: PublisherSimilar): string {
	const publisher = (similar.publisher ?? '').trim()
	return publisher === ''
		? t('versioniq', 'Similar name: {name} by an unknown publisher', { name: similar.name })
		: t('versioniq', 'Similar name: {name} by {publisher}', { name: similar.name, publisher })
}
</script>

<template>
	<div :class="$style.publisher" data-testid="publisher-mark">
		<p :class="$style.name" data-testid="publisher-name">
			{{ publisherLabel }}
		</p>
		<ul v-if="shownMarks.length > 0" :class="$style.marks" :aria-label="t('versioniq', 'Trust marks')">
			<li v-for="mark in shownMarks"
				:key="mark.id"
				:class="$style.mark"
				:title="mark.title"
				:data-mark="mark.id"
				data-testid="publisher-trust-mark">
				{{ mark.label }}
				<span :class="$style.hiddenVisually">({{ mark.title }})</span>
			</li>
		</ul>
		<p v-for="similar in similarTo"
			:key="similar.appId"
			:class="$style.similar"
			data-testid="publisher-similar-name">
			{{ similarLabel(similar) }}
		</p>
	</div>
</template>

<style module>
.publisher {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.name {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--default-font-size);
}

.marks {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.mark {
	display: inline-flex;
	align-items: center;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	color: var(--color-main-text);
	font-size: 11px;
	font-weight: 600;
}

.similar {
	margin: 0;
	color: var(--color-warning-text);
	font-size: var(--default-font-size);
}

.hiddenVisually {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}
</style>
