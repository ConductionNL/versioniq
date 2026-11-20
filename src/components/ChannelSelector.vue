<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md -->
<script setup lang="ts">
import type { ReleaseChannel } from '../utils/channelFilter.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { channelLabel, parseLtsLines, RELEASE_CHANNELS } from '../utils/channelFilter.ts'

const props = withDefaults(defineProps<{
	/** The app, or '' for the instance default on the Settings tab. */
	appId?: string
	/** The app's own channel, null when it follows the default; the default itself when appId is ''. */
	channel: string | null
	/** The instance default, for the "Use the default" option. */
	defaultChannel?: string
	/** The long-term support lines marked for the app. */
	ltsLines?: string[]
	disabled?: boolean
}>(), {
	appId: '',
	defaultChannel: 'server',
	ltsLines: () => [],
	disabled: false,
})

const emit = defineEmits<{
	(event: 'change', appId: string, channel: ReleaseChannel | null): void
	(event: 'ltsLines', appId: string, lines: string[]): void
}>()

type SelectOption = { id: ReleaseChannel | null, label: string }

const isDefault = computed(() => props.appId === '')

const options = computed<SelectOption[]>(() => {
	const channels = RELEASE_CHANNELS.map((channel) => ({ id: channel, label: channelLabel(channel) }))
	if (isDefault.value) {
		return channels
	}
	return [
		{ id: null, label: t('versioniq', 'Use the default ({channel})', { channel: channelLabel(props.defaultChannel) }) },
		...channels,
	]
})

const selected = computed<SelectOption>({
	get: () => options.value.find((option) => option.id === props.channel) ?? options.value[0],
	set: (option: SelectOption | null) => {
		emit('change', props.appId, option?.id ?? null)
	},
})

const effectiveChannel = computed(() => props.channel ?? props.defaultChannel)

const showLines = computed(() => !isDefault.value && effectiveChannel.value === 'lts')

const linesText = ref(props.ltsLines.join(', '))
watch(() => props.ltsLines, (lines) => {
	linesText.value = lines.join(', ')
})

const linesChanged = computed(() => parseLtsLines(linesText.value).join(',') !== props.ltsLines.join(','))

/**
 * Save the lines field as a list.
 */
function saveLines (): void {
	emit('ltsLines', props.appId, parseLtsLines(linesText.value))
}
</script>

<template>
	<div :class="$style.wrapper" data-testid="channel-selector">
		<NcSelect
			v-model="selected"
			data-testid="channel-select"
			:inputLabel="isDefault ? t('versioniq', 'Default release channel') : t('versioniq', 'Release channel')"
			:options="options"
			:clearable="false"
			:disabled="disabled"
			label="label" />
		<div v-if="showLines" :class="$style.lines" data-testid="channel-lts-lines">
			<NcTextField
				v-model="linesText"
				:label="t('versioniq', 'Long-term support lines')"
				:helperText="t('versioniq', 'Release lines such as 2.3 or 3, separated by commas')"
				:disabled="disabled"
				@keyup.enter="saveLines" />
			<NcButton
				variant="secondary"
				data-testid="channel-lts-save"
				:disabled="disabled || !linesChanged"
				@click="saveLines">
				{{ t('versioniq', 'Save lines') }}
			</NcButton>
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

.lines {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}
</style>
