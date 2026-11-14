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
	'OK',
])

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
			if (entry.name !== '__golden__' && entry.name !== 'node_modules') {
				out.push(...sourceFiles(full))
			}
		} else if (/\.(vue|ts|js)$/.test(entry.name) && !/\.spec\.(ts|js)$/.test(entry.name) && !/\.d\.ts$/.test(entry.name)) {
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
	const keys = []
	const tCall = new RegExp(String.raw`\bt\(\s*['"]${APP}['"]\s*,\s*(?:${STRING})`, 'g')
	for (const match of text.matchAll(tCall)) {
		keys.push({ key: unescape(match[1] ?? match[2]), line: lineOf(text, match.index) })
	}
	const nCall = new RegExp(String.raw`\bn\(\s*['"]${APP}['"]\s*,\s*(?:${STRING})\s*,\s*(?:${STRING})`, 'g')
	for (const match of text.matchAll(nCall)) {
		// Nextcloud keys a plural by both forms: `_<singular>_::_<plural>_`.
		const key = '_' + unescape(match[1] ?? match[2]) + '_::_' + unescape(match[3] ?? match[4]) + '_'
		keys.push({ key, line: lineOf(text, match.index) })
	}
	return keys
}

/**
 * The template text nodes of a single-file component that hold language.
 *
 * @param {string} text The file text.
 * @return {Array<{text: string, line: number}>} The bare texts.
 */
function bareTexts(text) {
	const open = text.indexOf('<template')
	const close = text.lastIndexOf('</template>')
	if (open === -1 || close === -1) {
		return []
	}
	const start = text.indexOf('>', open) + 1
	let body = text.slice(start, close)
	// Blank out what is not a text node, keeping offsets for line numbers.
	const blank = (whole) => whole.replace(/[^\n]/g, ' ')
	body = body.replace(/<!--[\s\S]*?-->/g, blank)
	body = body.replace(/<(?:[^>"']|"[^"]*"|'[^']*')*>/g, blank)
	body = body.replace(/\{\{[\s\S]*?\}\}/g, blank)
	const found = []
	for (const match of body.matchAll(/[^\s<>][^<>]*?(?=\s*(?:$|\n|\s{2,}))/g)) {
		const value = match[0].trim()
		const letters = (value.match(/\p{L}/gu) || []).length
		if (letters >= 2 && !ALLOWED.has(value)) {
			found.push({ text: value, line: lineOf(text, start + match.index) })
		}
	}
	return found
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
	const en = catalogue('en')
	const nl = catalogue('nl')
	const problems = []
	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8')
		const rel = path.relative(ROOT, file)
		for (const { key, line } of usedKeys(text)) {
			if (!en.has(key)) {
				problems.push(`${rel}:${line} key missing from l10n/en.json: ${JSON.stringify(key)}`)
			}
			if (!nl.has(key)) {
				problems.push(`${rel}:${line} key missing from l10n/nl.json: ${JSON.stringify(key)}`)
			}
		}
		if (file.endsWith('.vue')) {
			for (const { text: bare, line } of bareTexts(text)) {
				problems.push(`${rel}:${line} text outside t(): ${JSON.stringify(bare)}`)
			}
		}
	}
	return problems
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
