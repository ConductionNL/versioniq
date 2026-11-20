<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import type { SbomFormat } from '../utils/sbomDownload.ts'

import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { currentSbomPath, downloadSbom } from '../utils/sbomDownload.ts'

/**
 * The History tab's Export SBOM menu (inventory-export-sbom-metrics task 2.2):
 * the server and every installed app as CycloneDX 1.5 or SPDX 2.3 JSON.
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */
const error = ref('')
const isExporting = ref(false)

/**
 * Download the current inventory in one format.
 *
 * @param format cyclonedx or spdx
 */
async function exportAs (format: SbomFormat): Promise<void> {
	error.value = ''
	isExporting.value = true
	try {
		await downloadSbom(currentSbomPath(format), format === 'spdx' ? 'nextcloud-sbom.spdx.json' : 'nextcloud-sbom.cdx.json')
	} catch {
		error.value = t('versioniq', 'Could not export the SBOM.')
	} finally {
		isExporting.value = false
	}
}
</script>

<template>
	<div :class="$style.export" data-testid="sbom-export">
		<div :class="$style.menu" role="group" :aria-label="t('versioniq', 'Export SBOM')">
			<span :class="$style.label">{{ t('versioniq', 'Export SBOM') }}</span>
			<NcButton data-testid="sbom-export-cyclonedx" variant="secondary" :disabled="isExporting" @click="exportAs('cyclonedx')">
				{{ t('versioniq', 'CycloneDX 1.5 (JSON)') }}
			</NcButton>
			<NcButton data-testid="sbom-export-spdx" variant="secondary" :disabled="isExporting" @click="exportAs('spdx')">
				{{ t('versioniq', 'SPDX 2.3 (JSON)') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<style module>
.export { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }

.menu { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

.label { color: var(--color-text-maxcontrast); }
</style>
