<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
Skip and Unskip for the version an app card's update badge offers
(pinning-ranges-and-skips, design D2). The badge never shows a skipped version,
so the control starts as Skip; once skipped it shows "Skipped" with Unskip
until the next sweep offers the version after it.
@spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
-->
<script setup lang="ts">
import type { SkipEntry } from '../utils/skips.ts'

import { ref, watch } from 'vue'
import VersionSkipControl from './VersionSkipControl.vue'
import { fetchSkips } from '../utils/skips.ts'

const props = withDefaults(defineProps<{
	appId: string
	version: string
	disabled?: boolean
}>(), {
	disabled: false,
})

const emit = defineEmits<{
	(event: 'changed', appId: string): void
	(event: 'error', message: string): void
}>()

const skip = ref<SkipEntry | null>(null)

// A new sweep offers another version: its skip state starts fresh.
watch(() => [props.appId, props.version], () => {
	skip.value = null
})

/**
 * Reads the badge version's skip state after a skip or unskip, and tells the page.
 *
 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
 */
async function onChanged (): Promise<void> {
	skip.value = (await fetchSkips(props.appId))[props.version] ?? null
	emit('changed', props.appId)
}
</script>

<template>
	<div data-testid="update-badge-skip" @click.stop @keydown.stop>
		<VersionSkipControl
			:appId="appId"
			:version="version"
			:skip="skip"
			:disabled="disabled"
			@changed="onChanged"
			@error="(message: string) => emit('error', message)" />
	</div>
</template>
