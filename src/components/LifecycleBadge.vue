<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * The life cycle signals the availability sweep records for one app, as the
 * snapshot GET /api/updates serves them under `lifecycle`.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
 */
export interface LifecycleSignals {
	removedFromStore?: boolean | null
	archived?: boolean | null
	endOfSupport?: boolean | null
	noReleaseFor12Months?: boolean | null
	lastReleasedAt?: string | null
}

const props = withDefaults(defineProps<{
	lifecycle?: LifecycleSignals | null
}>(), { lifecycle: null })

/** The signals from strongest to weakest: the card shows the first that is set. */
const ORDER = ['removedFromStore', 'archived', 'endOfSupport', 'noReleaseFor12Months'] as const

type Signal = typeof ORDER[number]

/**
 * The label of one signal.
 *
 * @param signal The signal.
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
 */
function labelOf (signal: Signal): string {
	switch (signal) {
	case 'removedFromStore':
		return t('versioniq', 'Removed from the App Store')
	case 'archived':
		return t('versioniq', 'Repository archived')
	case 'endOfSupport':
		return t('versioniq', 'End of support')
	default:
		return t('versioniq', 'No release in a year')
	}
}

/** The signals that are set, strongest first; an unknown (null) signal is not set. */
const active = computed((): Signal[] => ORDER.filter((signal) => props.lifecycle?.[signal] === true))

/** The other signals, for the tooltip. */
const title = computed((): string => active.value.slice(1).map(labelOf).join(', '))
</script>

<template>
	<span
		v-if="active.length > 0"
		:class="$style.badge"
		:title="title"
		:data-signal="active[0]"
		data-testid="lifecycle-badge">
		{{ labelOf(active[0]) }}
	</span>
</template>

<style module>
.badge {
	display: inline-block;
	padding: 0 6px;
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-pill);
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 12px;
}
</style>
