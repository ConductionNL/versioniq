// SPDX-License-Identifier: EUPL-1.2
// The Instances tab's table (install-multi-instance-promotion D5): one column
// per instance in chain order, this one included, and one row per app id that
// appears on any of them. Kept out of the component so the rules are plain
// functions a spec can read.
// @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side

/** One app as a manifest reports it. */
export type ManifestApp = {
	appId: string
	installedVersion: string | null
	state?: string
	sourceId?: string | null
	sha256?: string | null
	runningSince: string | null
}

/** A manifest, this instance's own or a remote one. */
export type Manifest = {
	instanceName?: string
	stage?: string
	serverVersion?: string
	versioniqVersion?: string
	apps: ManifestApp[]
}

/** What the server remembers of the last read of a connection. */
export type RemoteState = {
	manifest: Manifest | null
	fetchedAt: string | null
	error: string | null
	checkedAt: string | null
}

/** A connection as `GET /api/instances` lists it, never with its password. */
export type InstanceConnection = {
	id: string
	name: string
	stage: string
	order: number
	baseUrl: string
	username: string
	addedBy?: string
	addedAt?: string
	remote?: RemoteState
}

/** This instance's own place in the chain. */
export type SelfInstance = {
	name: string
	stage: string
	order: number
}

/** The error RemoteInstanceReader stores for a remote without the manifest endpoint. */
export const ERROR_OLDER_VERSIONIQ = 'This instance runs an older Versioniq without instance support.'

/** One column of the table. */
export type InstanceColumn = {
	/** The connection id, or null for this instance. */
	id: string | null
	name: string
	stage: string
	order: number
	isSelf: boolean
	/** Whether this column comes before this instance in the chain, so its versions may be promoted here. */
	isEarlier: boolean
	/** When the shown manifest was read; null for this instance and for a remote never read. */
	fetchedAt: string | null
	/** The error of the last read, or null when it succeeded. */
	error: string | null
	/** The remote answers 404 on the manifest: it runs an older Versioniq. */
	olderVersioniq: boolean
	/** Apps by id, from the manifest shown. */
	apps: Map<string, ManifestApp>
	/** Whether any manifest is shown for this column. */
	hasManifest: boolean
}

/** One row of the table. */
export type InstanceRow = {
	appId: string
	/** Per column, in column order: the app there, or null when it is not installed. */
	cells: Array<ManifestApp | null>
	/** Whether the columns with a manifest show different versions. */
	differs: boolean
}

/**
 * @param manifest The manifest, or null.
 */
function appsOf (manifest: Manifest | null | undefined): Map<string, ManifestApp> {
	const apps = new Map<string, ManifestApp>()
	for (const app of manifest?.apps ?? []) {
		if (app && typeof app.appId === 'string' && app.appId !== '') {
			apps.set(app.appId, app)
		}
	}
	return apps
}

/**
 * The columns in chain order: by `order`, this instance placed at its own.
 * Equal orders keep this instance last, then the connections by name.
 *
 * @param self This instance's place in the chain.
 * @param own This instance's manifest, or null when it could not be read.
 * @param connections The connections, each with its remembered remote state.
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
 */
export function instanceColumns (self: SelfInstance, own: Manifest | null, connections: InstanceConnection[]): InstanceColumn[] {
	const remotes: InstanceColumn[] = connections.map((connection) => {
		const remote = connection.remote
		const manifest = remote?.manifest ?? null
		return {
			id: connection.id,
			name: connection.name,
			stage: connection.stage,
			order: connection.order,
			isSelf: false,
			isEarlier: connection.order < self.order,
			fetchedAt: remote?.fetchedAt ?? null,
			error: remote?.error ?? null,
			olderVersioniq: remote?.error === ERROR_OLDER_VERSIONIQ,
			apps: appsOf(manifest),
			hasManifest: manifest !== null,
		}
	})
	const selfColumn: InstanceColumn = {
		id: null,
		name: self.name,
		stage: self.stage,
		order: self.order,
		isSelf: true,
		isEarlier: false,
		fetchedAt: null,
		error: null,
		olderVersioniq: false,
		apps: appsOf(own),
		hasManifest: own !== null,
	}

	return [...remotes, selfColumn].sort((left, right) => {
		if (left.order !== right.order) {
			return left.order - right.order
		}
		if (left.isSelf !== right.isSelf) {
			return left.isSelf ? 1 : -1
		}
		return left.name.localeCompare(right.name)
	})
}

/**
 * One row per app id on any column, sorted by app id. A column without a
 * manifest takes no part in "differs": nothing is known about it.
 *
 * @param columns The columns, in chain order.
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
 */
export function instanceRows (columns: InstanceColumn[]): InstanceRow[] {
	const appIds = new Set<string>()
	for (const column of columns) {
		for (const appId of column.apps.keys()) {
			appIds.add(appId)
		}
	}

	return [...appIds].sort((left, right) => left.localeCompare(right)).map((appId) => {
		const cells = columns.map((column) => column.apps.get(appId) ?? null)
		const known = columns
			.map((column, index) => ({ column, cell: cells[index] }))
			.filter(({ column }) => column.hasManifest)
			.map(({ cell }) => cell?.installedVersion ?? null)

		return { appId, cells, differs: new Set(known).size > 1 }
	})
}

/**
 * Whether a cell can be promoted to this instance: an earlier stage runs a
 * version of it, and this instance runs another version or none.
 *
 * @param row The row.
 * @param columns The columns, in the row's order.
 * @param index The column of the cell.
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
 */
export function canPromoteFrom (row: InstanceRow, columns: InstanceColumn[], index: number): boolean {
	const column = columns[index]
	const version = row.cells[index]?.installedVersion ?? null
	if (!column || !column.isEarlier || column.id === null || version === null) {
		return false
	}
	const selfIndex = columns.findIndex((candidate) => candidate.isSelf)
	const own = selfIndex === -1 ? null : (row.cells[selfIndex]?.installedVersion ?? null)

	return own !== version
}

/**
 * Whole days between a moment and now, never negative; null for no or an unreadable moment.
 *
 * @param iso The moment, ISO 8601.
 * @param now The present.
 */
export function daysSince (iso: string | null, now: Date = new Date()): number | null {
	if (!iso) {
		return null
	}
	const moment = new Date(iso)
	if (Number.isNaN(moment.getTime())) {
		return null
	}

	return Math.max(0, Math.floor((now.getTime() - moment.getTime()) / 86400000))
}
