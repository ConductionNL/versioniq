<!--
SPDX-License-Identifier: EUPL-1.2
Records a known issue against one version of an app: a text, an optional
workaround and an optional https link. Owns its own password-confirmed write,
like PinDialog, so App.vue only opens it and stores the returned list from the
`saved` event (releases-version-facts D4).
@spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
-->
<script setup lang="ts">
import type { AdminKnownIssue } from '../utils/versionFacts.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsWrite } from '../ocs.ts'

const props = defineProps<{
	open: boolean
	appId: string
	version: string
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	saved: [version: string, issues: AdminKnownIssue[]]
}>()

const text = ref('')
const workaround = ref('')
const link = ref('')
const saving = ref(false)
const error = ref('')

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		text.value = ''
		workaround.value = ''
		link.value = ''
		error.value = ''
	}
})

/**
 * The link as typed is an https address, or empty.
 */
const linkError = computed((): string => {
	const value = link.value.trim()
	if (value === '' || /^https:\/\/\S+$/i.test(value)) {
		return ''
	}

	return t('versioniq', 'The link must start with https://.')
})

/**
 * Save the issue; the server answers with the version's notes after the change.
 */
async function save (): Promise<void> {
	if (text.value.trim() === '') {
		error.value = t('versioniq', 'Describe the issue.')
		return
	}
	if (linkError.value) {
		error.value = linkError.value
		return
	}
	saving.value = true
	error.value = ''
	try {
		const body: Record<string, unknown> = { text: text.value.trim(), workaround: workaround.value.trim() }
		if (link.value.trim()) {
			body.link = link.value.trim()
		}
		const { payload, error: apiError } = await ocsWrite<{ knownIssues: AdminKnownIssue[] }>(
			'PUT',
			`/ocs/v2.php/apps/versioniq/api/app/${encodeURIComponent(props.appId)}/versions/${encodeURIComponent(props.version)}/known-issues`,
			body,
		)
		if (apiError) {
			error.value = apiError
			return
		}
		emit('saved', props.version, Array.isArray(payload.knownIssues) ? payload.knownIssues : [])
		emit('update:open', false)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not save the known issue.')
	} finally {
		saving.value = false
	}
}

const buttons = computed(() => [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		disabled: saving.value,
		callback: () => emit('update:open', false),
	},
	{
		label: t('versioniq', 'Save'),
		type: 'primary' as const,
		disabled: saving.value,
		callback: save,
	},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Known issue in {appId} {version}', { appId, version })"
		:buttons="buttons"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div :class="$style.body">
			<NcTextField
				:modelValue="text"
				:label="t('versioniq', 'Issue')"
				:disabled="saving"
				@update:modelValue="(value: string) => (text = value)" />
			<NcTextField
				:modelValue="workaround"
				:label="t('versioniq', 'Workaround (optional)')"
				:disabled="saving"
				@update:modelValue="(value: string) => (workaround = value)" />
			<NcTextField
				:modelValue="link"
				:label="t('versioniq', 'Link (optional, https)')"
				:disabled="saving"
				:error="linkError !== ''"
				:helperText="linkError"
				@update:modelValue="(value: string) => (link = value)" />
			<p v-if="error" :class="$style.error" role="alert">
				{{ error }}
			</p>
		</div>
	</NcDialog>
</template>

<style module>
.body {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.error {
	margin: 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
