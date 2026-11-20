<!--
SPDX-License-Identifier: EUPL-1.2
Install from a file (install-manual-upload D1, D2): the admin picks a .tar.gz
app archive and confirms their password; Versioniq reads the archive and shows
its app id, version, SHA-256 and whether the App Store's signature verifies it,
before anything is installed. An unsigned archive needs the SHA-256 the admin
expects, or their acceptance of the shown one, and "Allow unsigned uploads" in
the Settings tab. Both requests use strict password confirmation, so the
password travels with them as Basic credentials.
@spec openspec/changes/install-manual-upload/tasks.md#task-3.1
-->
<script setup lang="ts">
import type { InspectedUpload } from '../utils/uploadInstall.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { currentLoginName } from '../utils/enableApp.ts'
import { inspectArchive, installUpload } from '../utils/uploadInstall.ts'

const props = defineProps<{
	open: boolean
	/** The app whose detail the dialog was opened from; empty when none. */
	appId: string
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	installed: [payload: Record<string, unknown>]
}>()

const file = ref<File | null>(null)
const password = ref('')
const inspected = ref<InspectedUpload | null>(null)
const expectedSha = ref('')
const acceptShown = ref(false)
const busy = ref(false)
const error = ref('')

/** Clears everything, so a reopened dialog starts at picking a file. */
function reset (): void {
	file.value = null
	password.value = ''
	inspected.value = null
	expectedSha.value = ''
	acceptShown.value = false
	busy.value = false
	error.value = ''
}

watch(() => props.open, (open) => {
	if (!open) {
		reset()
	}
})

/**
 * @param event The change event of the file input.
 */
function onFilePicked (event: Event): void {
	const picked = (event.target as HTMLInputElement).files?.[0] ?? null
	file.value = picked
	inspected.value = null
	error.value = ''
}

const otherApp = computed(() => inspected.value !== null && props.appId !== '' && inspected.value.appId !== props.appId)

const canInstall = computed(() => inspected.value !== null && !busy.value && password.value !== ''
	&& (inspected.value.signedByStore || expectedSha.value.trim() !== '' || acceptShown.value))

/** Uploads the archive and shows what it is. */
async function readArchive (): Promise<void> {
	if (file.value === null) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		inspected.value = await inspectArchive(file.value, { user: currentLoginName(), password: password.value })
	} catch (failure) {
		error.value = failure instanceof Error ? failure.message : t('versioniq', 'Could not read the archive.')
	} finally {
		busy.value = false
	}
}

/** Installs the inspected archive; a refusal stays in the dialog. */
async function install (): Promise<void> {
	if (inspected.value === null) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const outcome = await installUpload({ token: inspected.value.token, expectedSha256: expectedSha.value, acceptShown: acceptShown.value }, { user: currentLoginName(), password: password.value })
		if (!outcome.ok) {
			error.value = outcome.message
			return
		}
		emit('installed', outcome.payload)
		emit('update:open', false)
	} catch (failure) {
		error.value = failure instanceof Error ? failure.message : t('versioniq', 'Could not install the archive.')
	} finally {
		busy.value = false
	}
}

const buttons = computed(() => [
	{
		label: t('versioniq', 'Cancel'),
		type: 'tertiary' as const,
		callback: () => emit('update:open', false),
	},
	inspected.value === null
		? {
			label: t('versioniq', 'Read archive'),
			type: 'primary' as const,
			disabled: busy.value || file.value === null || password.value === '',
			callback: () => {
				readArchive()
				return false
			},
		}
		: {
			label: t('versioniq', 'Install {appId} {version}', { appId: inspected.value.appId, version: inspected.value.version }),
			type: 'primary' as const,
			disabled: !canInstall.value,
			callback: () => {
				install()
				return false
			},
		},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('versioniq', 'Install from a file')"
		:buttons="buttons"
		@update:open="(value: boolean) => emit('update:open', value)">
		<p :class="$style.text">
			{{ t('versioniq', 'Pick a .tar.gz app archive. Versioniq reads its app id, version and SHA-256 and shows them before anything is installed.') }}
		</p>
		<label :class="$style.fileLabel">
			{{ t('versioniq', 'App archive') }}
			<input
				type="file"
				accept=".tar.gz,.tgz,application/gzip"
				data-testid="upload-file"
				:disabled="busy"
				@change="onFilePicked">
		</label>
		<NcPasswordField
			v-model="password"
			:label="t('versioniq', 'Password')"
			autocomplete="current-password" />
		<template v-if="inspected">
			<dl :class="$style.facts" data-testid="upload-facts">
				<dt>{{ t('versioniq', 'App') }}</dt>
				<dd>{{ inspected.appId }}</dd>
				<dt>{{ t('versioniq', 'Version') }}</dt>
				<dd>{{ inspected.version }}</dd>
				<dt>{{ t('versioniq', 'SHA-256') }}</dt>
				<dd :class="$style.sha">{{ inspected.sha256 }}</dd>
				<dt>{{ t('versioniq', 'Signature') }}</dt>
				<dd data-testid="upload-signed">
					{{ inspected.signedByStore ? t('versioniq', 'Signed by the App Store') : t('versioniq', 'Not signed by the App Store') }}
				</dd>
			</dl>
			<NcNoteCard v-if="otherApp" type="warning">
				{{ t('versioniq', 'This archive is {archiveApp}, not {app}.', { archiveApp: inspected.appId, app: appId }) }}
			</NcNoteCard>
			<template v-if="!inspected.signedByStore">
				<NcNoteCard type="warning" data-testid="upload-unsigned-warning">
					{{ t('versioniq', 'An archive the App Store does not sign runs whatever code it contains. It installs only when "Allow unsigned uploads" is on in the Settings tab.') }}
				</NcNoteCard>
				<NcTextField
					v-model="expectedSha"
					:label="t('versioniq', 'Expected SHA-256')"
					:helperText="t('versioniq', 'Paste the checksum the supplier gave you.')"
					data-testid="upload-expected-sha" />
				<NcCheckboxRadioSwitch
					v-model="acceptShown"
					type="checkbox"
					data-testid="upload-accept-shown">
					{{ t('versioniq', 'I accept the SHA-256 shown above') }}
				</NcCheckboxRadioSwitch>
			</template>
		</template>
		<p v-if="error" :class="$style.error" data-testid="upload-error">{{ error }}</p>
	</NcDialog>
</template>

<style module>
.text {
	margin-bottom: 12px;
}

.fileLabel {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.facts {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 12px;
	margin: 12px 0;
}

.facts dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.sha {
	font-family: var(--font-face, monospace);
	word-break: break-all;
}

.error {
	margin-top: 8px;
	color: var(--color-error-text);
}
</style>
