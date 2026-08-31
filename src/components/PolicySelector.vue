<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export type PolicyLevel = 'none' | 'patch' | 'minor' | 'all'

const props = withDefaults(defineProps<{
	appId: string
	level: PolicyLevel
	autoUpdateEnabled: boolean
	disabled?: boolean
}>(), {
	disabled: false,
})

const emit = defineEmits<{
	(event: 'change', appId: string, level: PolicyLevel): void
}>()

type SelectOption = { id: PolicyLevel, label: string }

const options = computed<SelectOption[]>(() => [
	{ id: 'none', label: t('versioniq', 'Off') },
	{ id: 'patch', label: t('versioniq', 'Patch') },
	{ id: 'minor', label: t('versioniq', 'Minor') },
	{ id: 'all', label: t('versioniq', 'All') },
])

const selected = computed<SelectOption>({
	get: () => options.value.find((option) => option.id === props.level) ?? options.value[0],
	set: (option: SelectOption | null) => {
		emit('change', props.appId, option?.id ?? 'none')
	},
})

const isActive = computed(() => props.level !== 'none')
</script>

<template>
	<div :class="$style.wrapper" data-testid="policy-selector">
		<NcSelect
			v-model="selected"
			data-testid="policy-select"
			:inputLabel="t('versioniq', 'Auto-update policy')"
			:options="options"
			:clearable="false"
			:disabled="disabled"
			label="label" />
		<span v-if="isActive" :class="$style.badge" data-testid="policy-active-badge">
			{{ t('versioniq', 'Auto-update: {level}', { level: selected.label }) }}
		</span>
		<span v-if="isActive && !autoUpdateEnabled" :class="$style.disabledHint" data-testid="policy-disabled-hint">
			{{ t('versioniq', 'Automation disabled — enable it in settings to take effect.') }}
		</span>
	</div>
</template>

<style module>
.wrapper {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.badge {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.disabledHint {
	font-size: 12px;
	color: var(--color-warning-text, #a94b0a);
}
</style>
