// SPDX-License-Identifier: EUPL-1.2
// Downloads an SBOM from the OCS API (inventory-export-sbom-metrics D5). The
// route needs the OCS-APIRequest header, which a plain link cannot send, so the
// file is fetched and handed to the browser as a blob.
//
// @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
import { apiUrl, ocsHeaders } from '../ocs.ts'

export type SbomFormat = 'cyclonedx' | 'spdx'

export const SBOM_PATH = '/ocs/v2.php/apps/versioniq/api/sbom'

/**
 * The file name the server gave the download, or a fallback.
 *
 * @param disposition the Content-Disposition header, or null
 * @param fallback the name to use when it names none
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */
export function fileNameOf (disposition: string | null, fallback: string): string {
	const match = /filename="?([^";]+)"?/i.exec(disposition ?? '')
	return match ? match[1] : fallback
}

/**
 * Fetch an SBOM and save it.
 *
 * @param path the route below the webroot: the current inventory or one install's copy
 * @param fallbackName the file name when the server names none
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */
export async function downloadSbom (path: string, fallbackName: string): Promise<void> {
	const response = await fetch(apiUrl(path), { headers: { ...ocsHeaders } })
	if (!response.ok) {
		throw new Error(`HTTP ${response.status}`)
	}
	const blob = await response.blob()
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = fileNameOf(response.headers.get('Content-Disposition'), fallbackName)
	document.body.appendChild(link)
	link.click()
	link.remove()
	URL.revokeObjectURL(url)
}

/**
 * The route of the current inventory in one format.
 *
 * @param format cyclonedx or spdx
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */
export function currentSbomPath (format: SbomFormat): string {
	return `${SBOM_PATH}?format=${format}`
}
