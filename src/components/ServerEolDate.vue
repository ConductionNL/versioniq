<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import { ocsGet } from '../ocs.ts'

/**
 * The end-of-life date of the running Nextcloud major, from the feed the admin
 * configured (inventory-lifecycle-signals, design D3). Nothing is shown when
 * the feed is off, cannot be read or does not list the major: an unknown date
 * is not a warning.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
 */
const major = ref(0)
const eolDate = ref<string | null>(null)

onMounted(async () => {
	try {
		const result = await ocsGet<{ major?: number, eolDate?: string | null }>('/ocs/v2.php/apps/versioniq/api/v1/lifecycle/server')
		major.value = result?.payload?.major ?? 0
		eolDate.value = result?.payload?.eolDate ?? null
	} catch {
		eolDate.value = null
	}
})
</script>

<template>
	<p v-if="eolDate && major > 0" data-testid="server-eol-date">
		{{ t('versioniq', 'Nextcloud {major} reaches end of life on {date}.', { major: String(major), date: eolDate }) }}
	</p>
</template>
