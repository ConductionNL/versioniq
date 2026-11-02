// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
// Golden test: pins the sign compareVersions returns for every ordered pair of
// a version matrix (cores of unequal length, prerelease identifiers that are
// numeric, alphanumeric, missing or longer on one side). Set RECORD_GOLDEN=1
// to rewrite the fixture.
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { compareVersions } from './versionCompare.ts'

const FIXTURE = resolve(process.cwd(), 'src/utils/__golden__/versionCompare.json')

const VERSIONS = [
	'0', '1', '1.0', '1.0.0', '1.0.0.0', '1.0.1', '1.1', '1.10.0', '1.2.0', '2.0.0', '10.0.0',
	'1.0.0-0', '1.0.0-1', '1.0.0-2', '1.0.0-10', '1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.2',
	'1.0.0-alpha.10', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11',
	'1.0.0-rc.1', '1.0.0-rc.1.1', '1.0.0-rc1', '1.0.0-RC1', '1.0.0-1.alpha', '1.0.0-alpha.1.0',
	'1.0.1-unstable.20261102001547', '1.0.1-unstable.20261101000000', '1..0', '1.0.', '.1',
	'1.0.0-', '1.0.0-a-b', '2.0.0-beta-2', 'v1.0.0', '1.0.0-0.0', '1.0.0-00',
]

function sign (value: number): number {
	return value > 0 ? 1 : value < 0 ? -1 : 0
}

function record (): Record<string, number> {
	const result: Record<string, number> = {}
	for (const left of VERSIONS) {
		for (const right of VERSIONS) {
			result[`${left} | ${right}`] = sign(compareVersions(left, right))
		}
	}
	return result
}

describe('compareVersions golden', () => {
	const actual = record()
	if (process.env.RECORD_GOLDEN === '1') {
		writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
	}
	const expected = JSON.parse(readFileSync(FIXTURE, 'utf8')) as Record<string, number>

	it('covers every recorded pair', () => {
		expect(Object.keys(actual).sort()).toEqual(Object.keys(expected).sort())
	})

	for (const key of Object.keys(actual)) {
		it(key, () => {
			expect(actual[key]).toBe(expected[key])
		})
	}
})
