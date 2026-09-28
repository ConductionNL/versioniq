<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { getCanonicalLocale, t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * When a version was released, from `releasedAt` on the version listing (the
 * App Store's `created`, a forge's `published_at`, as ISO 8601 UTC). The spec
 * asks every version row for its release date and no source read one (#480).
 * An unknown date renders nothing rather than a guess.
 *
 * @spec openspec/specs/version-management/spec.md
 */
const props = defineProps<{
	releasedAt: string | null
}>()

const date = computed((): Date | null => {
	if (!props.releasedAt) {
		return null
	}
	const parsed = new Date(props.releasedAt)

	return Number.isNaN(parsed.getTime()) ? null : parsed
})

const shortDate = computed((): string => date.value
	? new Intl.DateTimeFormat(getCanonicalLocale(), { dateStyle: 'medium', timeZone: 'UTC' }).format(date.value)
	: '')

const fullDate = computed((): string => date.value
	? new Intl.DateTimeFormat(getCanonicalLocale(), { dateStyle: 'full', timeStyle: 'short' }).format(date.value)
	: '')
</script>

<template>
	<time
		v-if="date"
		:class="$style.date"
		data-testid="release-date"
		:datetime="releasedAt ?? ''"
		:title="fullDate">
		{{ t('versioniq', 'Released {date}', { date: shortDate }) }}
	</time>
</template>

<style module>
.date {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>
