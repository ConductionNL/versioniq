// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import type {ExportEntry} from './auditExport.ts';

import { describe, expect, it, vi } from 'vitest'
import { collectAll, EXPORT_LIMIT, toCsv, toJson } from './auditExport.ts'

function row (id: number, extra: Partial<ExportEntry> = {}): ExportEntry {
  return {
	id,
	actorUid: 'admin',
	appId: 'fixtureapp',
	operation: 'install',
	fromVersion: '1.0.0',
	toVersion: '1.0.1',
	sourceId: null,
	status: 'success',
	message: null,
	createdAt: '2026-11-14 09:00:00',
	...extra,
}
}

describe('auditExport', () => {
	it('writes the on-screen columns plus the fixed advisory ids, quoting where needed', () => {
		const csv = toCsv([
			row(1, { fixedAdvisories: [{ id: 'GHSA-1', severity: 'high' }, { id: 'GHSA-2', severity: 'low' }] }),
			row(2, { message: 'said "no", then, stopped' }),
		])

		expect(csv.split('\r\n')).toEqual([
			'createdAt,actorUid,appId,operation,fromVersion,toVersion,sourceId,status,message,fixedAdvisories',
			'2026-11-14 09:00:00,admin,fixtureapp,install,1.0.0,1.0.1,,success,,GHSA-1 GHSA-2',
			'2026-11-14 09:00:00,admin,fixtureapp,install,1.0.0,1.0.1,,success,"said ""no"", then, stopped",',
			'',
		])
	})

	it('writes JSON as the rows themselves', () => {
		expect(JSON.parse(toJson([row(3)]))).toEqual([row(3)])
	})

	it('reads pages until a short one', async () => {
		const fetchPage = vi.fn(async (offset: number, limit: number) => {
			const total = 250
			return Array.from({ length: Math.max(0, Math.min(limit, total - offset)) }, (_, i) => row(offset + i))
		})

		const { rows, cut } = await collectAll(fetchPage)

		expect(rows).toHaveLength(250)
		expect(cut).toBe(false)
		expect(fetchPage.mock.calls).toEqual([[0, 200], [200, 200]])
	})

	it('stops at the limit and says when rows were left out', async () => {
		const fetchPage = vi.fn(async (offset: number, limit: number) => Array.from({ length: limit }, (_, i) => row(offset + i)))

		const { rows, cut } = await collectAll(fetchPage)

		expect(rows).toHaveLength(EXPORT_LIMIT)
		expect(cut).toBe(true)
		expect(fetchPage).toHaveBeenLastCalledWith(EXPORT_LIMIT, 1)
	})

	it('does not report a cut when the history is exactly at the limit', async () => {
		const fetchPage = vi.fn(async (offset: number, limit: number) => (offset >= EXPORT_LIMIT ? [] : Array.from({ length: limit }, (_, i) => row(offset + i))))

		expect((await collectAll(fetchPage)).cut).toBe(false)
	})
})
