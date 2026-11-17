<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { TriagedAdvisory } from '../utils/advisories.ts'

import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { dismissReasonLabel } from '../utils/advisories.ts'

/**
 * The advisories a dismissal still holds for one app or the server, collapsed:
 * the reason, comment, who, when and until when, and a Reopen button.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 */
defineProps<{
	advisories: TriagedAdvisory[]
	busy?: boolean
}>()

const emit = defineEmits<{
	reopen: [advisoryId: string]
}>()

/**
 * A stored time as a local date, or the raw value when it cannot be read.
 *
 * @param value An ISO 8601 time.
 */
function day (value: string | null | undefined): string {
	const time = Date.parse(value ?? '')
	return Number.isNaN(time) ? (value ?? '') : new Date(time).toLocaleDateString()
}
</script>

<template>
	<details v-if="advisories.length > 0" :class="$style.dismissed" data-testid="advisories-dismissed">
		<summary>{{ n('versioniq', '%n dismissed advisory', '%n dismissed advisories', advisories.length) }}</summary>
		<ul :class="$style.list">
			<li v-for="advisory in advisories" :key="advisory.id" :class="$style.item" data-testid="dismissed-item">
				<p :class="$style.line">
					<code>{{ advisory.id }}</code>
					<span>{{ advisory.summary }}</span>
				</p>
				<p :class="$style.line">
					<strong>{{ dismissReasonLabel(advisory.dismissal?.reason ?? '') }}</strong>
					<span v-if="advisory.dismissal?.comment">{{ advisory.dismissal.comment }}</span>
				</p>
				<p :class="$style.meta">
					{{ t('versioniq', 'Dismissed by {admin} on {date}', { admin: advisory.dismissal?.by ?? '', date: day(advisory.dismissal?.at) }) }}
					<template v-if="advisory.dismissal?.until">
						· {{ t('versioniq', 'until {date}', { date: day(advisory.dismissal.until) }) }}
					</template>
				</p>
				<NcButton
					variant="secondary"
					size="small"
					:disabled="busy"
					data-testid="advisory-reopen"
					@click="emit('reopen', advisory.id)">
					{{ t('versioniq', 'Reopen') }}
				</NcButton>
			</li>
		</ul>
	</details>
</template>

<style module>
.dismissed {
	margin-top: 4px;
}

.list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
}

.item {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	padding: 4px 8px;
	border-inline-start: 2px solid var(--color-border-dark);
}

.line {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0;
}

.meta {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
