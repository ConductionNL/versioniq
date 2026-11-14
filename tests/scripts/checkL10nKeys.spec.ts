/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * scripts/check-l10n-keys.cjs (admin-translate-apps-tab D2): it fails on a
 * template with bare English text and on a key missing from a catalogue,
 * naming file and line, and passes on the tree as shipped.
 */

import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const SCRIPT = path.resolve(__dirname, '../../scripts/check-l10n-keys.cjs')

/**
 * Run the check on one scratch component.
 *
 * @param source The component source.
 * @return The exit code and stderr.
 */
function runOn(source: string): { status: number | null, stderr: string } {
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'l10n-keys-'))
	const file = path.join(dir, 'Scratch.vue')
	fs.writeFileSync(file, source)
	const result = spawnSync(process.execPath, [SCRIPT, file], { encoding: 'utf8' })
	fs.rmSync(dir, { recursive: true, force: true })
	return { status: result.status, stderr: result.stderr }
}

describe('check-l10n-keys', () => {
	it('fails on a button with bare English text and names the line', () => {
		const { status, stderr } = runOn('<template>\n\t<div>\n\t\t<button>Install now</button>\n\t</div>\n</template>\n')
		expect(status).toBe(1)
		expect(stderr).toContain('Scratch.vue:3 text outside t(): "Install now"')
	})

	it('fails on a key that is in neither catalogue', () => {
		const { status, stderr } = runOn("<template>\n\t<p>{{ t('versioniq', 'A sentence nobody translated') }}</p>\n</template>\n")
		expect(status).toBe(1)
		expect(stderr).toContain('key missing from l10n/en.json: "A sentence nobody translated"')
		expect(stderr).toContain('key missing from l10n/nl.json: "A sentence nobody translated"')
	})

	it('accepts translated text, interpolations, attributes and allowed names', () => {
		const { status, stderr } = runOn([
			'<template>',
			"\t<p :title=\"t('versioniq', 'Install')\" class=\"note\">{{ t('versioniq', 'Install') }} {{ app.label }}</p>",
			"\t<span v-if=\"a < b\">{{ n('versioniq', 'Install debug (%n step)', 'Install debug (%n steps)', count) }}</span>",
			'\t<!-- A comment is no text -->',
			'\t<b>Nextcloud</b> → 1.2',
			'</template>',
		].join('\n'))
		expect(stderr).toBe('')
		expect(status).toBe(0)
	})

	it('passes on the tree as shipped', () => {
		const result = spawnSync(process.execPath, [SCRIPT], { encoding: 'utf8' })
		expect(result.stderr).toBe('')
		expect(result.status).toBe(0)
	})
})
