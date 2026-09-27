<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'

/**
 * A source for this app's version list and install only, without changing its
 * binding: the `?source=` override the API and `occ versioniq:install
 * --source` already had, now on the page (#438).
 *
 * @spec openspec/specs/external-sources/spec.md
 */
const props = defineProps<{
	modelValue: string
	boundSourceId: string | null
	disabled?: boolean
}>()

const emit = defineEmits<{
	(event: 'apply', source: string): void
}>()

const draft = ref(props.modelValue)
watch(() => props.modelValue, (value) => {
	draft.value = value
})
</script>

<template>
	<div :class="$style.field" data-testid="source-override">
		<label :class="$style.label" for="source-override-input">
			{{ t('versioniq', 'Use another source this once') }}
		</label>
		<div :class="$style.row">
			<input
				id="source-override-input"
				v-model="draft"
				type="text"
				placeholder="github:owner/repo"
				:class="$style.input"
				:disabled="disabled"
				data-testid="source-override-input">
			<NcButton
				variant="secondary"
				:disabled="disabled || draft.trim() === ''"
				data-testid="source-override-apply"
				@click="emit('apply', draft.trim())">
				{{ t('versioniq', 'Load versions') }}
			</NcButton>
			<NcButton
				v-if="modelValue !== ''"
				variant="tertiary"
				:disabled="disabled"
				data-testid="source-override-clear"
				@click="emit('apply', '')">
				{{ t('versioniq', 'Use the bound source') }}
			</NcButton>
		</div>
		<p :class="$style.hint">
			{{ t('versioniq', 'Applies to this version list and the next install only. The binding stays {source}.', { source: boundSourceId ?? 'appstore' }) }}
		</p>
	</div>
</template>

<style module>
.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 8px 0;
}

.label {
	font-weight: bold;
}

.row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.input {
	min-width: 16em;
}

.hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
