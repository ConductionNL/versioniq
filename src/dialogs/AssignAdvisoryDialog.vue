<!--
SPDX-License-Identifier: EUPL-1.2
Assign one advisory to an admin or a group, or remove the assignment. Owns
its write call; the parent opens it and takes the triage map from the
`assigned` event.
@spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
-->
<script setup lang="ts">
import type { AdvisoryAssignee, AdvisoryTriageMap } from '../utils/advisories.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsWrite } from '../ocs.ts'

const props = defineProps<{
	open: boolean
	appId: string
	advisoryId: string
	current?: AdvisoryAssignee | null
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	assigned: [triage: AdvisoryTriageMap]
}>()

const type = ref<'user' | 'group'>('user')
const id = ref('')
const saving = ref(false)
const error = ref('')

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		type.value = props.current?.type ?? 'user'
		id.value = props.current?.id ?? ''
		error.value = ''
	}
})

/**
 * Sends the assignment, or its removal when `assignee` is empty.
 *
 * @param assignee `user:{uid}`, `group:{gid}` or ''.
 */
async function send (assignee: string): Promise<void> {
	saving.value = true
	error.value = ''
	try {
		const { payload, error: apiError } = await ocsWrite<{ triage: AdvisoryTriageMap }>(
			'POST',
			'/ocs/v2.php/apps/versioniq/api/advisory-triage/assign',
			{ appId: props.appId, advisoryId: props.advisoryId, assignee },
		)
		if (apiError) {
			error.value = apiError
			return
		}
		emit('assigned', payload.triage ?? {})
		emit('update:open', false)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('versioniq', 'Could not assign this advisory.')
	} finally {
		saving.value = false
	}
}

type DialogButton = { label: string, variant: 'primary' | 'tertiary', disabled: boolean, callback: () => unknown }

const buttons = computed((): DialogButton[] => {
	const list: DialogButton[] = []
	if (props.current) {
		list.push({ label: t('versioniq', 'Unassign'), variant: 'tertiary', disabled: saving.value, callback: () => send('') })
	}
	list.push(
		{ label: t('versioniq', 'Cancel'), variant: 'tertiary', disabled: false, callback: () => emit('update:open', false) },
		{
			label: t('versioniq', 'Assign'),
			variant: 'primary',
			disabled: id.value.trim() === '' || saving.value,
			callback: () => send(`${type.value}:${id.value.trim()}`),
		},
	)
	return list
})
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Assign {advisory}', { advisory: advisoryId })"
		:buttons="buttons"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div :class="$style.body" data-testid="assign-advisory-dialog">
			<fieldset :class="$style.types">
				<legend>{{ t('versioniq', 'Assign to') }}</legend>
				<NcCheckboxRadioSwitch
					type="radio"
					name="assign-type"
					value="user"
					:modelValue="type"
					:disabled="saving"
					@update:modelValue="() => (type = 'user')">
					{{ t('versioniq', 'An admin') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					type="radio"
					name="assign-type"
					value="group"
					:modelValue="type"
					:disabled="saving"
					@update:modelValue="() => (type = 'group')">
					{{ t('versioniq', 'A group') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcTextField
				:modelValue="id"
				:label="type === 'group' ? t('versioniq', 'Group ID') : t('versioniq', 'User ID of an admin')"
				:disabled="saving"
				data-testid="assign-id"
				@update:modelValue="(value: string | number) => (id = String(value))" />
			<NcNoteCard v-if="type === 'group'" type="info">
				{{ t('versioniq', 'Only the admins in this group are notified.') }}
			</NcNoteCard>
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

.types {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	border: none;
}

.error {
	margin: 0;
	color: var(--color-error-text);
}
</style>
