<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Controller;

use OCA\Versioniq\Service\Inventory\SbomExporter;
use OCA\Versioniq\Service\Inventory\SbomWriter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The inventory of the instance as an SBOM download (inventory-export-sbom-metrics
 * D5). Admin-only: an SBOM names every version that runs.
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */
class SbomController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SbomExporter $exporter,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Downloads the current inventory as CycloneDX 1.5 or SPDX 2.3 JSON (admin-only).
	 *
	 * @param string $format cyclonedx (the default) or spdx
	 *
	 * @return DataDownloadResponse<Http::STATUS_OK, string, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The SBOM as a download
	 * 400: The format is not cyclonedx or spdx
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
	 */
	#[ApiRoute(verb: 'GET', url: '/api/sbom')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/sbom', postfix: 'v1')]
	public function export(string $format = SbomWriter::FORMAT_CYCLONEDX): DataDownloadResponse|DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if (!in_array($format, SbomWriter::FORMATS, true)) {
			return new DataResponse(['message' => 'format must be one of: ' . implode(', ', SbomWriter::FORMATS) . '.'], Http::STATUS_BAD_REQUEST);
		}

		return new DataDownloadResponse(
			$this->exporter->export($format),
			$this->exporter->fileName($format),
			SbomExporter::MEDIA_TYPES[$format],
		);
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}
}
