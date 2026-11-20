<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * The "About" card of an app's version view: who publishes it, under which
 * licence, what is installed, its declared links and its description, read
 * from the installed info.xml (inventory-app-details-card D1, D4). The server
 * already dropped any link that is not http or https; the card renders what
 * is left, in a new tab without a referrer opener.
 *
 * @spec openspec/changes/inventory-app-details-card/tasks.md#2-page
 */
const props = defineProps<{
	app: {
		id: string
		label: string
		description?: string
		summary?: string
		author?: string
		licence?: string
		links?: { website: string | null, repository: string | null, bugs: string | null }
		installedVersion?: string | null
		state?: 'enabled' | 'disabled' | 'notInstalled'
	}
}>()

const links = computed(() => {
	const declared = props.app.links ?? { website: null, repository: null, bugs: null }
	return [
		{ key: 'website', label: t('versioniq', 'Website'), url: declared.website },
		{ key: 'repository', label: t('versioniq', 'Source'), url: declared.repository },
		{ key: 'bugs', label: t('versioniq', 'Report a problem'), url: declared.bugs },
	].filter((link): link is { key: string, label: string, url: string } => typeof link.url === 'string' && link.url !== '')
})

const installed = computed(() => {
	if (props.app.state === 'notInstalled' || !props.app.installedVersion) {
		return t('versioniq', 'Not installed')
	}
	const state = props.app.state === 'disabled' ? t('versioniq', 'disabled') : t('versioniq', 'enabled')
	return `${props.app.installedVersion} (${state})`
})

const description = computed(() => (props.app.description || props.app.summary || '').trim())
</script>

<template>
	<section :class="$style.card" data-testid="app-about-card" :aria-label="t('versioniq', 'About {app}', { app: app.label || app.id })">
		<h3 :class="$style.title">
			{{ t('versioniq', 'About {app}', { app: app.label || app.id }) }}
		</h3>
		<dl :class="$style.facts">
			<dt>{{ t('versioniq', 'Publisher') }}</dt>
			<dd data-testid="app-about-publisher">
				{{ app.author || '—' }}
			</dd>
			<dt>{{ t('versioniq', 'Licence') }}</dt>
			<dd data-testid="app-about-licence">
				{{ app.licence || '—' }}
			</dd>
			<dt>{{ t('versioniq', 'Installed') }}</dt>
			<dd data-testid="app-about-installed">
				{{ installed }}
			</dd>
		</dl>
		<ul v-if="links.length > 0" :class="$style.links">
			<li v-for="link in links" :key="link.key">
				<a
					:href="link.url"
					target="_blank"
					rel="noopener noreferrer"
					:data-testid="`app-about-link-${link.key}`">{{ link.label }}</a>
			</li>
		</ul>
		<p v-if="description" :class="$style.description" data-testid="app-about-description">
			{{ description }}
		</p>
	</section>
</template>

<style module>
.card {
	margin-top: 12px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.title {
	margin: 0 0 8px;
	font-size: 16px;
}

.facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 0;
}

.facts dt {
	color: var(--color-text-maxcontrast);
}

.facts dd {
	margin: 0;
}

.links {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.links a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.description {
	margin: 8px 0 0;
	white-space: pre-line;
}
</style>
