import type { InstallPayload } from './installResult.ts'

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
// Golden test: pins what normalizeInstallResult returns for every payload
// field taken one at a time across absent, empty and typed values, and for
// the full update type / from / to / message matrix that decides the
// downgrade message. Set RECORD_GOLDEN=1 to rewrite the fixture.
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { normalizeInstallResult } from './installResult.ts'

const FIXTURE = resolve(process.cwd(), 'src/utils/__golden__/installResult.json')
const ABSENT = Symbol('absent')

const FIELD_VALUES: Record<string, unknown[]> = {
	appId: [ABSENT, null, '', 0, false, 'myapp'],
	fromVersion: [ABSENT, null, '', '1.0.0'],
	toVersion: [ABSENT, null, '', 0, false, '1.0.0'],
	installedVersion: [ABSENT, null, '', '1.0.0'],
	updateType: [ABSENT, null, '', 'upgrade', 'downgrade'],
	message: [ABSENT, null, '', 'App updated.'],
	dryRun: [ABSENT, null, false, true, 0, 1, 'false'],
	installStatus: [ABSENT, null, '', 'installed'],
	stage: [ABSENT, null, '', 'download'],
	category: [ABSENT, null, '', 'network'],
	hint: [ABSENT, null, '', 'Retry.'],
	debug: [ABSENT, null, '', 'x', {}, [], [{ stage: 'a', data: 1 }]],
	recordedShaMatched: [ABSENT, null, false, true, 0],
	integrityWarning: [ABSENT, null, '', 'unverified', 0, true],
	servedFromCache: [ABSENT, null, false, true, 1, 'true'],
}

function build (fields: Record<string, unknown>): InstallPayload {
	const payload: Record<string, unknown> = {}
	for (const [name, value] of Object.entries(fields)) {
		if (value !== ABSENT) {
			payload[name] = value
		}
	}
	return payload as InstallPayload
}

function cases (): InstallPayload[] {
	const list: InstallPayload[] = []
	for (const [name, values] of Object.entries(FIELD_VALUES)) {
		for (const value of values) {
			list.push(build({ [name]: value }))
		}
	}
	for (const updateType of [ABSENT, 'downgrade', 'upgrade', 'none']) {
		for (const fromVersion of [ABSENT, null, '', '1.0.0', '2.0.0', '1.5.0', '10.0.0']) {
			for (const toVersion of [ABSENT, '', '1.5.0', '1.5.0-beta.1', '0.0.0-a']) {
				for (const message of [ABSENT, '', 'App updated.', 'Other.']) {
					list.push(build({ updateType, fromVersion, toVersion, message }))
				}
			}
		}
	}
	return list
}

function serialize (value: unknown): string {
	return JSON.stringify(value, (_key, inner) => (inner === undefined ? '(undefined)' : inner))
}

function record (): Record<string, string> {
	const result: Record<string, string> = {}
	for (const payload of cases()) {
		result[serialize(payload)] = serialize(normalizeInstallResult(payload))
	}
	return result
}

describe('normalizeInstallResult golden', () => {
	const actual = record()
	if (process.env.RECORD_GOLDEN === '1') {
		writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
	}
	const expected = JSON.parse(readFileSync(FIXTURE, 'utf8')) as Record<string, string>

	it('covers every recorded payload', () => {
		expect(Object.keys(actual).sort()).toEqual(Object.keys(expected).sort())
	})

	for (const key of Object.keys(actual)) {
		it(key, () => {
			expect(actual[key]).toBe(expected[key])
		})
	}
})
