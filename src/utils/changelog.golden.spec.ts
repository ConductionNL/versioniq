// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
// Golden test: pins buildChangelogRange for every ordered pair of endpoints
// (empty, equal, upgrade, downgrade, prerelease) over several version lists
// (unsorted, duplicated, absent or null changelog). Set RECORD_GOLDEN=1 to
// rewrite the fixture.
import type { ChangelogRangeEntry, ChangelogSource } from './changelog.ts'

import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { buildChangelogRange } from './changelog.ts'

const FIXTURE = resolve(process.cwd(), 'src/utils/__golden__/changelog.json')

const ENDPOINTS = ['', '1.0.0', '1.2.0', '1.2.0-beta.1', '1.10.0', '2.0.0', '3.0.0', '0.9']

const LISTS: Record<string, ChangelogSource[]> = {
	empty: [],
	descending: [
		{ version: '3.0.0', changelog: 'three' },
		{ version: '2.0.0', changelog: null },
		{ version: '1.10.0', changelog: 'ten' },
		{ version: '1.2.0', changelog: '' },
		{ version: '1.2.0-beta.1' },
		{ version: '1.0.0', changelog: 'one' },
	],
	unsorted: [
		{ version: '1.2.0' },
		{ version: '3.0.0', changelog: 'three' },
		{ version: '1.0.0', changelog: undefined },
		{ version: '1.10.0', changelog: 'ten' },
		{ version: '2.0.0', changelog: 'two' },
		{ version: '1.2.0-beta.1', changelog: 'beta' },
	],
	duplicated: [
		{ version: '2.0.0', changelog: 'first' },
		{ version: '1.2.0', changelog: 'a' },
		{ version: '2.0.0', changelog: 'second' },
		{ version: '1.2.0', changelog: null },
		{ version: '2.0', changelog: 'short' },
	],
}

function run (installed: string, target: string, versions: ChangelogSource[]): ChangelogRangeEntry[] {
	return buildChangelogRange({ installed, target }, versions)
}

function record (): Record<string, ChangelogRangeEntry[]> {
	const result: Record<string, ChangelogRangeEntry[]> = {}
	for (const [name, list] of Object.entries(LISTS)) {
		for (const installed of ENDPOINTS) {
			for (const target of ENDPOINTS) {
				result[`${name} | ${installed || '(empty)'} -> ${target || '(empty)'}`] = run(installed, target, list)
			}
		}
	}
	return result
}

describe('buildChangelogRange golden', () => {
	const actual = record()
	if (process.env.RECORD_GOLDEN === '1') {
		writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
	}
	const expected = JSON.parse(readFileSync(FIXTURE, 'utf8')) as Record<string, ChangelogRangeEntry[]>

	it('covers every recorded case', () => {
		expect(Object.keys(actual).sort()).toEqual(Object.keys(expected).sort())
	})

	for (const key of Object.keys(actual)) {
		it(key, () => {
			expect(actual[key]).toStrictEqual(expected[key])
		})
	}
})
