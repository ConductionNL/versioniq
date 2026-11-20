<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'

/**
 * The card badges that say an installed app lost the trust it was installed
 * with (safety-withdrawn-and-revoked): "Certificate revoked" when the daily
 * recheck found its signing certificate on the revocation list (`certificateRevoked`
 * on GET /api/apps, design D2, tooltip in the wording of D4), and "Version
 * withdrawn by the publisher" when a successful listing no longer contains
 * the installed version (`installedWithdrawn` in the GET /api/updates
 * snapshot, design D3). An unknown (null) withdrawn state shows nothing.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-a-withdrawn-installed-version-is-marked-and-never-reinstalled-by-accident
 */
withDefaults(defineProps<{
	certificateRevoked?: boolean
	installedWithdrawn?: boolean | null
}>(), { certificateRevoked: false, installedWithdrawn: null })
</script>

<template>
	<p v-if="certificateRevoked || installedWithdrawn === true" :class="$style.badges">
		<span
			v-if="certificateRevoked"
			:class="[$style.badge, $style.revoked]"
			:title="t('versioniq', 'Nextcloud revoked the signing certificate of this app. Nextcloud revokes a certificate to withdraw an app it knows to be compromised or malicious.')"
			data-testid="certificate-revoked-badge">
			{{ t('versioniq', 'Certificate revoked') }}
		</span>
		<span
			v-if="installedWithdrawn === true"
			:class="[$style.badge, $style.withdrawn]"
			data-testid="version-withdrawn-badge">
			{{ t('versioniq', 'Version withdrawn by the publisher') }}
		</span>
	</p>
</template>

<style module>
.badges {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0;
}

.badge {
	display: inline-block;
	padding: 0 6px;
	border: 1px solid;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
}

.revoked {
	border-color: var(--color-error);
	color: var(--color-error-text, var(--color-main-text));
}

.withdrawn {
	border-color: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
}
</style>
