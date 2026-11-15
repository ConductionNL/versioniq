<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'

/**
 * The two signals that code an admin already runs lost the trust it was
 * installed with (safety-withdrawn-and-revoked): Nextcloud revoked the app's
 * signing certificate (the daily recheck, design D2, worded as design D4),
 * or the publisher withdrew the installed version (the availability sweep,
 * design D3). Unknown renders nothing.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md
 */
defineProps<{
	certificateRevoked: boolean
	installedWithdrawn: boolean | null
}>()
</script>

<template>
	<span
		v-if="certificateRevoked"
		:class="[$style.badge, $style.revoked]"
		data-testid="certificate-revoked-badge"
		:title="t('versioniq', 'Nextcloud revoked the signing certificate of this app. Nextcloud revokes a certificate to withdraw an app it knows to be compromised or malicious.')">
		{{ t('versioniq', 'Certificate revoked') }}
	</span>
	<span
		v-if="installedWithdrawn === true"
		:class="[$style.badge, $style.withdrawn]"
		data-testid="version-withdrawn-badge"
		:title="t('versioniq', 'The source no longer lists the installed version. Pick another version to install.')">
		{{ t('versioniq', 'Version withdrawn by the publisher') }}
	</span>
</template>

<style module>
.badge {
	display: inline-flex;
	align-items: center;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 600;
}

.revoked {
	border-color: var(--color-error);
	color: var(--color-error-text);
}

.withdrawn {
	border-color: var(--color-warning);
	color: var(--color-warning-text);
}
</style>
