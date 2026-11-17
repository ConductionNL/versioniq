<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<script setup lang="ts">
import { loadState } from '@nextcloud/initial-state'
import { getLanguage, t } from '@nextcloud/l10n'
// Expandable per-version release-notes disclosure — "Per-version changelog
// display" (add-changelog-visibility). Renders notes as a plain text node
// only (mustache interpolation, never v-html), so a release body containing
// markup such as `<script>` is always inert. A machine translation is
// rendered the same way.
//
// When the instance has a translation provider (initial state
// `notes-translation-available`) and the notes are in another language than
// the admin's, or in an unknown one, a button asks the server to translate
// them (releases-notes-reach D3). The translation is labelled as a machine
// translation and the original stays one click away.
//
// @spec openspec/specs/changelog-visibility/spec.md
// @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
import { computed, ref } from 'vue'
import { ocsPost } from '../ocs.ts'

const props = withDefaults(defineProps<{
	version: string
	changelog: string | null
	changelogLanguage?: string | null
	appId?: string
	source?: string
}>(), {
	changelogLanguage: null,
	appId: '',
	source: '',
})

const TRANSLATE = '/ocs/v2.php/apps/versioniq/api/changelog/translate'

type TranslationPayload = { translation?: string, message?: string }

const expanded = ref(false)
const translation = ref<string | null>(null)
const showingTranslation = ref(false)
const translating = ref(false)
const translateError = ref('')

const translationAvailable = loadState<boolean>('versioniq', 'notes-translation-available', false) === true
const userLanguage = getLanguage()

const hasChangelog = computed(() => Boolean(props.changelog && props.changelog.trim() !== ''))

/**
 * The base of a language code: `pt_BR` and `pt-BR` are `pt`.
 *
 * @param code the language code
 */
function baseLanguage (code: string): string {
	return code.toLowerCase().split(/[_-]/)[0] ?? ''
}

const canTranslate = computed(() => translationAvailable
	&& hasChangelog.value
	&& props.appId !== ''
	&& (props.changelogLanguage === null || baseLanguage(props.changelogLanguage) !== baseLanguage(userLanguage)))

/**
 * The admin's language by name, in that language, else its code.
 */
function languageName (): string {
	try {
		return new Intl.DisplayNames([userLanguage.replace('_', '-')], { type: 'language' }).of(userLanguage.replace('_', '-')) ?? userLanguage
	} catch {
		return userLanguage
	}
}

const translateLabel = computed(() => t('versioniq', 'Translate to {language}', { language: languageName() }))

/**
 *
 */
function toggle (): void {
	expanded.value = !expanded.value
}

/**
 * The request body: the app, the version, the admin's language and a source override when one is set.
 */
function translateBody (): Record<string, unknown> {
	const body: Record<string, unknown> = { appId: props.appId, version: props.version, language: userLanguage }
	if (props.source.trim() !== '') {
		body.source = props.source.trim()
	}
	return body
}

/**
 * Keep and show a translation the server answered, or show why there is none.
 *
 * @param payload what the server answered
 * @param error whether the request failed
 */
function takeTranslation (payload: TranslationPayload | null | undefined, error: boolean): void {
	if (error || typeof payload?.translation !== 'string') {
		translateError.value = payload?.message || t('versioniq', 'The release notes could not be translated.')
		return
	}
	translation.value = payload.translation
	showingTranslation.value = true
}

/**
 * Ask the server for the translation, or show the one already fetched.
 */
async function translate (): Promise<void> {
	if (translation.value !== null) {
		showingTranslation.value = true
		return
	}
	translating.value = true
	translateError.value = ''
	try {
		const { payload, error } = await ocsPost<TranslationPayload>(TRANSLATE, translateBody())
		takeTranslation(payload, Boolean(error))
	} catch {
		translateError.value = t('versioniq', 'The release notes could not be translated.')
	} finally {
		translating.value = false
	}
}
</script>

<template>
	<div :class="$style.disclosure">
		<button
			type="button"
			:class="$style.toggle"
			:aria-expanded="expanded ? 'true' : 'false'"
			:aria-controls="`changelog-body-${version}`"
			data-testid="changelog-toggle"
			@click="toggle">
			<span :class="$style.chevron" aria-hidden="true">{{ expanded ? '▾' : '▸' }}</span>
			{{ t('versioniq', 'Release notes') }}
		</button>
		<div
			v-if="expanded"
			:id="`changelog-body-${version}`"
			:class="$style.body"
			data-testid="changelog-body">
			<template v-if="hasChangelog">
				<template v-if="showingTranslation && translation !== null">
					<p :class="$style.machineLabel" data-testid="changelog-machine-label">
						{{ t('versioniq', 'Machine translation') }}
					</p>
					<p :class="$style.text" data-testid="changelog-translation">
						{{ translation }}
					</p>
				</template>
				<p v-else :class="$style.text" data-testid="changelog-text">
					{{ changelog }}
				</p>
				<div v-if="canTranslate" :class="$style.translateRow">
					<button
						v-if="showingTranslation"
						type="button"
						:class="$style.translateButton"
						data-testid="changelog-show-original"
						@click="showingTranslation = false">
						{{ t('versioniq', 'Show original') }}
					</button>
					<button
						v-else
						type="button"
						:class="$style.translateButton"
						:disabled="translating"
						data-testid="changelog-translate"
						@click="translate">
						{{ translating ? t('versioniq', 'Translating…') : translateLabel }}
					</button>
					<span v-if="translateError" role="alert" :class="$style.translateError" data-testid="changelog-translate-error">
						{{ translateError }}
					</span>
				</div>
			</template>
			<p v-else :class="$style.placeholder" data-testid="changelog-placeholder">
				{{ t('versioniq', 'No release notes provided') }}
			</p>
		</div>
	</div>
</template>

<style module src="../styles/changelogText.module.css"></style>

<style module>
.disclosure {
	width: 100%;
}

.toggle {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: none;
	border: none;
	padding: 2px 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	cursor: pointer;
}

.toggle:hover {
	color: var(--color-main-text);
	text-decoration: underline;
}

.chevron {
	display: inline-block;
	width: 10px;
}

.body {
	margin-top: 4px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-background-hover);
}


.machineLabel {
	margin: 0 0 var(--default-grid-baseline);
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.translateRow {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.translateButton {
	min-height: var(--default-clickable-area);
	padding: 0 calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, var(--border-radius-large));
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.translateButton:disabled {
	cursor: default;
	opacity: 0.7;
}

.translateError {
	color: var(--color-error-text, var(--color-error));
}
</style>
