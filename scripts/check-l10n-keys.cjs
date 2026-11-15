#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-l10n-keys.cjs — every translatable string reaches both catalogues.
//
// WHY THIS EXISTS
//
//   A `t('versioniq', '…')` key that is missing from l10n/nl.json renders in
//   English for a Dutch admin, and a template text node outside `t()` can
//   never be translated at all. Nothing threw on either, so half of the Apps
//   tab stayed English while the app shipped a Dutch catalogue
//   (openspec/changes/admin-translate-apps-tab, design D2; hydra ADR-057).
//
// WHAT IT CHECKS
//
//   1. Every literal first argument of `t('versioniq', …)`, and the
//      `_<singular>_::_<plural>_` key of `n('versioniq', …)`, in
//      src/**/*.vue, *.ts and *.js (spec files excluded) is a key in
//      l10n/en.json and in l10n/nl.json.
//   2. No `<template>` holds a text node with two or more letters outside
//      `{{ }}`. Text inside an interpolation is code; text outside it is what
//      the admin reads, so it must come from `t()`. ALLOWED lists the few
//      texts that are not language (product names, symbols).
//
// Usage: node scripts/check-l10n-keys.cjs [file ...]
//   With files, only those are read (for a scratch-file test); without, all
//   of src/. Exit 0 when clean, 1 with one line per problem: file:line reason.

'use strict'

const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const APP = 'versioniq'

// Texts that are not language: product and protocol names, and symbols.
const ALLOWED = new Set([
	'Versioniq',
	'Nextcloud',
	'GitHub',
	'GitLab',
	'Packagist',
	'npm',
	'JSON',
	'OK',
])

/** Directories under src/ the check does not read. */
const SKIPPED_DIRS = new Set(['__golden__', 'node_modules'])

/**
 * Whether a file name is source the check reads: a component or script, not a spec or a declaration.
 *
 * @param {string} name The file name.
 * @return {boolean}
 */
function isSourceFile(name) {
	return /\.(vue|ts|js)$/.test(name) && !/\.spec\.(ts|js)$/.test(name) && !/\.d\.ts$/.test(name)
}

/**
 * Every source file the check reads.
 *
 * @param {string} dir The directory.
 * @return {string[]} The files.
 */
function sourceFiles(dir) {
	const out = []
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			out.push(...(SKIPPED_DIRS.has(entry.name) ? [] : sourceFiles(full)))
		} else if (isSourceFile(entry.name)) {
			out.push(full)
		}
	}
	return out
}

/**
 * The 1-based line of an offset.
 *
 * @param {string} text The file text.
 * @param {number} offset The offset.
 * @return {number} The line.
 */
function lineOf(text, offset) {
	return text.slice(0, offset).split('\n').length
}

/**
 * Unescape a JS string literal body.
 *
 * @param {string} body The literal without its quotes.
 * @return {string} The value.
 */
function unescape(body) {
	return body.replace(/\\(.)/g, (whole, ch) => ({ n: '\n', t: '\t' }[ch] ?? ch))
}

const STRING = String.raw`'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"`

/**
 * The translation keys a file uses, with their lines.
 *
 * @param {string} text The file text.
 * @return {Array<{key: string, line: number}>} The keys.
 */
function usedKeys(text) {
	return [...singularKeys(text), ...pluralKeys(text)]
}

/**
 * The keys of a file's t() calls, with their lines.
 *
 * @param {string} text The file text.
 * @return {Array<{key: string, line: number}>} The keys.
 */
function singularKeys(text) {
	const tCall = new RegExp(String.raw`\bt\(\s*['"]${APP}['"]\s*,\s*(?:${STRING})`, 'g')
	return [...text.matchAll(tCall)].map((match) => ({ key: unescape(match[1] ?? match[2]), line: lineOf(text, match.index) }))
}

/**
 * The keys of a file's n() calls, with their lines. Nextcloud keys a plural
 * by both forms: `_<singular>_::_<plural>_`.
 *
 * @param {string} text The file text.
 * @return {Array<{key: string, line: number}>} The keys.
 */
function pluralKeys(text) {
	const nCall = new RegExp(String.raw`\bn\(\s*['"]${APP}['"]\s*,\s*(?:${STRING})\s*,\s*(?:${STRING})`, 'g')
	return [...text.matchAll(nCall)].map((match) => ({
		key: '_' + unescape(match[1] ?? match[2]) + '_::_' + unescape(match[3] ?? match[4]) + '_',
		line: lineOf(text, match.index),
	}))
}

/**
 * The template text nodes of a single-file component that hold language.
 *
 * @param {string} text The file text.
 * @return {Array<{text: string, line: number}>} The bare texts.
 */
function bareTexts(text) {
	const template = templateTextNodes(text)
	if (template === null) {
		return []
	}
	const found = []
	for (const match of template.body.matchAll(/[^\s<>][^<>]*?(?=\s*(?:$|\n|\s{2,}))/g)) {
		const value = match[0].trim()
		if (isLanguage(value)) {
			found.push({ text: value, line: lineOf(text, template.start + match.index) })
		}
	}
	return found
}

/**
 * A component's template with everything but its text nodes blanked out,
 * offsets kept for line numbers; null without a template.
 *
 * @param {string} text The file text.
 * @return {{body: string, start: number}|null} The template body and where it starts.
 */
function templateTextNodes(text) {
	const open = text.indexOf('<template')
	const close = text.lastIndexOf('</template>')
	if (open === -1 || close === -1) {
		return null
	}
	const start = text.indexOf('>', open) + 1
	const blank = (whole) => whole.replace(/[^\n]/g, ' ')
	const body = text.slice(start, close)
		.replace(/<!--[\s\S]*?-->/g, blank)
		.replace(/<(?:[^>"']|"[^"]*"|'[^']*')*>/g, blank)
		.replace(/\{\{[\s\S]*?\}\}/g, blank)
	return { body, start }
}

/**
 * Whether a text node holds language: two letters or more, and not a name every language writes the same.
 *
 * @param {string} value The trimmed text.
 * @return {boolean}
 */
function isLanguage(value) {
	return (value.match(/\p{L}/gu) || []).length >= 2 && !ALLOWED.has(value)
}

/**
 * Read a catalogue's keys.
 *
 * @param {string} locale The locale.
 * @return {Set<string>} The keys.
 */
function catalogue(locale) {
	const json = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', locale + '.json'), 'utf8'))
	return new Set(Object.keys(json.translations || {}))
}

/**
 * Run the check.
 *
 * @param {string[]} files The files to read, or none for all of src/.
 * @return {string[]} The problems.
 */
function check(files) {
	const catalogues = { en: catalogue('en'), nl: catalogue('nl') }
	return files.flatMap((file) => fileProblems(file, catalogues))
}

/**
 * The problems of one file: keys a catalogue lacks, and in a component, text outside t().
 *
 * @param {string} file The file.
 * @param {Object<string, Set<string>>} catalogues The keys of each catalogue, by locale.
 * @return {string[]} The problems.
 */
function fileProblems(file, catalogues) {
	const text = fs.readFileSync(file, 'utf8')
	const rel = path.relative(ROOT, file)
	const problems = []
	for (const { key, line } of usedKeys(text)) {
		for (const [locale, keys] of Object.entries(catalogues)) {
			if (!keys.has(key)) {
				problems.push(`${rel}:${line} key missing from l10n/${locale}.json: ${JSON.stringify(key)}`)
			}
		}
	}
	const bare = file.endsWith('.vue') ? bareTexts(text) : []
	return problems.concat(bare.map(({ text: value, line }) => `${rel}:${line} text outside t(): ${JSON.stringify(value)}`))
}

if (require.main === module) {
	const args = process.argv.slice(2)
	const files = args.length > 0 ? args.map((file) => path.resolve(file)) : sourceFiles(path.join(ROOT, 'src'))
	const problems = check(files)
	for (const problem of problems) {
		console.error(problem)
	}
	if (problems.length > 0) {
		console.error(`check-l10n-keys: ${problems.length} problem(s)`)
		process.exit(1)
	}
	console.log(`check-l10n-keys: ${files.length} file(s), every key in en.json and nl.json, no bare template text`)
}

module.exports = { usedKeys, bareTexts, check }
