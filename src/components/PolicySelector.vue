<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export type PolicyLevel = 'none' | 'patch' | 'minor' | 'all'

/** A version whose automatic update failed; the job skips it until retried. */
export type BlockedVersion = { version: string, at: string }

const props = withDefaults(defineProps<{
	appId: string
	level: PolicyLevel
	autoUpdateEnabled: boolean
	disabled?: boolean
	blockedVersions?: BlockedVersion[]
}>(), {
	disabled: false,
	blockedVersions: () => [],
})

const emit = defineEmits<{
	(event: 'change', appId: string, level: PolicyLevel): void
	(event: 'retry', appId: string, version: string): void
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
			{{ t('versioniq', 'Automation is disabled. Enable it in settings to take effect.') }}
		</span>
		<div v-if="blockedVersions.length > 0" :class="$style.blocked" data-testid="policy-blocked-versions">
			<span :class="$style.blockedHint">
				{{ t('versioniq', 'Automatic updates skip these versions because an earlier attempt failed:') }}
			</span>
			<span v-for="entry in blockedVersions" :key="entry.version" :class="$style.blockedRow">
				<code>{{ entry.version }}</code>
				<NcButton
					variant="tertiary"
					:disabled="disabled"
					:data-testid="`policy-retry-${entry.version}`"
					@click="emit('retry', appId, entry.version)">
					{{ t('versioniq', 'Retry {version}', { version: entry.version }) }}
				</NcButton>
			</span>
		</div>
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

.blocked {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.blockedHint {
	font-size: 12px;
	color: var(--color-warning-text, #a94b0a);
}

.blockedRow {
	display: flex;
	align-items: center;
	gap: 8px;
}

.disabledHint {
	font-size: 12px;
	color: var(--color-warning-text, #a94b0a);
}
</style>
