<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
One version's skip state in the version picker (pinning-ranges-and-skips):
"Skipped" with the reason and Unskip, or Skip with an optional reason. A
skipped version is never chosen by the nightly job and never shown as the
pending update; later versions arrive as usual.
@spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
-->
<script setup lang="ts">
import type { SkipEntry } from '../utils/skips.ts'

import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { skipVersion, unskipVersion } from '../utils/skips.ts'

const props = withDefaults(defineProps<{
	appId: string
	version: string
	skip?: SkipEntry | null
	disabled?: boolean
}>(), {
	skip: null,
	disabled: false,
})

const emit = defineEmits<{
	(event: 'changed'): void
	(event: 'error', message: string): void
}>()

const asking = ref(false)
const reason = ref('')
const busy = ref(false)

/**
 * Run one change and report it.
 *
 * @param change The skip or unskip call.
 * @param failure The message when it fails.
 */
async function run (change: () => Promise<void>, failure: string): Promise<void> {
	busy.value = true
	try {
		await change()
		asking.value = false
		reason.value = ''
		emit('changed')
	} catch (e) {
		emit('error', e instanceof Error && e.message ? e.message : failure)
	} finally {
		busy.value = false
	}
}

const skip = (): Promise<void> => run(() => skipVersion(props.appId, props.version, reason.value), t('versioniq', 'Could not skip this version.'))
const unskip = (): Promise<void> => run(() => unskipVersion(props.appId, props.version), t('versioniq', 'Could not unskip this version.'))
</script>

<template>
	<div :class="$style.wrapper" :data-testid="`version-skip-${version}`">
		<template v-if="props.skip">
			<span :class="$style.badge" data-testid="version-skipped-badge">
				{{ t('versioniq', 'Skipped') }}<template v-if="props.skip.reason">: {{ props.skip.reason }}</template>
			</span>
			<NcButton
				variant="tertiary"
				:disabled="disabled || busy"
				data-testid="version-unskip"
				@click="unskip">
				{{ t('versioniq', 'Unskip') }}
			</NcButton>
		</template>
		<template v-else-if="asking">
			<NcTextField
				v-model="reason"
				:label="t('versioniq', 'Why skip {version}? (optional)', { version })"
				data-testid="version-skip-reason" />
			<NcButton
				variant="secondary"
				:disabled="disabled || busy"
				data-testid="version-skip-confirm"
				@click="skip">
				{{ t('versioniq', 'Skip {version}', { version }) }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="asking = false">
				{{ t('versioniq', 'Cancel') }}
			</NcButton>
		</template>
		<NcButton
			v-else
			variant="tertiary"
			:disabled="disabled"
			data-testid="version-skip"
			@click="asking = true">
			{{ t('versioniq', 'Skip this version') }}
		</NcButton>
	</div>
</template>

<style module>
.wrapper {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.badge {
	font-size: 12px;
	color: var(--color-warning-text);
}
</style>
