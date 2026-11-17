<!--
SPDX-License-Identifier: EUPL-1.2
Dismiss one advisory of one app (or of the server) with a reason, an
optional comment and an optional end date, which "A fix is planned" needs.
Owns its write call; the parent opens it and takes the triage map from the
`dismissed` event.
@spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
-->
<script setup lang="ts">
import type { AdvisoryTriageMap } from '../utils/advisories.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { ocsWrite } from '../ocs.ts'
import { DISMISS_REASONS, dismissReasonLabel } from '../utils/advisories.ts'

const props = defineProps<{
	open: boolean
	appId: string
	advisoryId: string
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	dismissed: [triage: AdvisoryTriageMap]
}>()

/** The longest comment the server keeps. */
const COMMENT_MAX = 1000

const reason = ref('')
const comment = ref('')
const until = ref<Date | null>(null)
const saving = ref(false)
const error = ref('')

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		reason.value = ''
		comment.value = ''
		until.value = null
		error.value = ''
	}
})

/**
 * A picked end date as `YYYY-MM-DD` in local time, or '' for none.
 *
 * @param value The picked date.
 */
function untilDay (value: Date | null): string {
	if (value === null || Number.isNaN(value.getTime())) {
		return ''
	}
	const month = String(value.getMonth() + 1).padStart(2, '0')
	const date = String(value.getDate()).padStart(2, '0')
	return `${value.getFullYear()}-${month}-${date}`
}

const needsUntil = computed(() => reason.value === 'fix_planned')
const canSave = computed(() => reason.value !== ''
	&& comment.value.length <= COMMENT_MAX
	&& (!needsUntil.value || until.value !== null)
	&& !saving.value)

/**
 * Sends the dismissal and hands the triage map after it to the parent.
 */
async function confirmDismiss (): Promise<void> {
	saving.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsWrite<{ triage: AdvisoryTriageMap }>(
			'POST',
			'/ocs/v2.php/apps/versioniq/api/advisory-triage/dismiss',
			{ appId: props.appId, advisoryId: props.advisoryId, reason: reason.value, comment: comment.value.trim(), until: untilDay(until.value) },
		)
		if (apiError) {
			error.value = apiError
			return
		}
		emit('dismissed', payload.triage ?? {})
		emit('update:open', false)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not dismiss this advisory.')
	} finally {
		saving.value = false
	}
}

const buttons = computed(() => [
	{
		label: t('versioniq', 'Cancel'),
		variant: 'tertiary' as const,
		callback: () => emit('update:open', false),
	},
	{
		label: t('versioniq', 'Dismiss advisory'),
		variant: 'primary' as const,
		disabled: !canSave.value,
		callback: confirmDismiss,
	},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Dismiss {advisory}', { advisory: advisoryId })"
		:buttons="buttons"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div :class="$style.body" data-testid="dismiss-advisory-dialog">
			<p :class="$style.hint">
				{{ t('versioniq', 'A dismissed advisory stops counting for the badge, notifications, the weekly digest and security installs. It ends on its end date, and when the installed version changes.') }}
			</p>
			<fieldset :class="$style.reasons">
				<legend>{{ t('versioniq', 'Reason') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="option in DISMISS_REASONS"
					:key="option"
					type="radio"
					name="dismiss-reason"
					:value="option"
					:modelValue="reason"
					:disabled="saving"
					:data-testid="`dismiss-reason-${option}`"
					@update:modelValue="(value: string) => (reason = value)">
					{{ dismissReasonLabel(option) }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcTextArea
				:modelValue="comment"
				:label="t('versioniq', 'Comment (optional)')"
				:maxlength="COMMENT_MAX"
				:disabled="saving"
				data-testid="dismiss-comment"
				@update:modelValue="(value: string | number) => (comment = String(value))" />
			<NcDateTimePickerNative
				id="dismiss-advisory-until"
				:modelValue="until"
				type="date"
				:label="needsUntil ? t('versioniq', 'End date') : t('versioniq', 'End date (optional)')"
				data-testid="dismiss-until"
				@update:modelValue="(value: Date | null) => (until = value)" />
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

.reasons {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	border: none;
}

.hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.error {
	margin: 0;
	color: var(--color-error-text);
}
</style>
