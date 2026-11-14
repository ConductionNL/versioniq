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

use InvalidArgumentException;
use OCA\Versioniq\Service\Installer\UploadedArchive;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Install from a file, step one: read an uploaded app archive and show what it
 * is before anything is installed (install-manual-upload D1).
 *
 * Admin-only twice over, like SettingsController: no NoAdminRequired
 * attribute, and the explicit isAdmin() guard. Uploading code is a strict
 * password-confirmed action.
 *
 * @psalm-suppress UnusedClass
 */
class UploadController extends OCSController {
	/** The multipart field that carries the archive. */
	public const FIELD = 'archive';

	public function __construct(
		string $appName,
		IRequest $request,
		private UploadedArchive $uploads,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Reads an uploaded app archive (multipart field `archive`, a `.tar.gz` of at most 256 MB) and keeps it for an hour; installs nothing
	 *
	 * @return DataResponse<Http::STATUS_OK, array{token: string, appId: string, version: string, sha256: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: The app id and version the archive's info.xml declares, its SHA-256, and the token to install it with
	 * 400: No archive was uploaded, or the upload failed
	 * 403: Caller is not an administrator
	 * 422: The archive is too large, not a .tar.gz, or has no usable appinfo/info.xml
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.1
	 */
	#[PasswordConfirmationRequired(strict: true)]
	#[ApiRoute(verb: 'POST', url: '/api/upload/inspect')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/upload/inspect', postfix: 'v1')]
	public function inspect(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$file = $this->request->getUploadedFile(self::FIELD);
		$path = $this->uploadedPath($file);
		if ($path === null) {
			return new DataResponse(['message' => 'Upload a .tar.gz app archive in the field "archive".'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$inspected = $this->uploads->store($path);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse($inspected);
	}

	/**
	 * The temporary path of a successful upload, or null.
	 *
	 * @param mixed $file What IRequest::getUploadedFile() returned.
	 */
	private function uploadedPath(mixed $file): ?string {
		if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return null;
		}

		$path = $file['tmp_name'] ?? null;
		if (!is_string($path) || $path === '' || !$this->isUpload($path)) {
			return null;
		}

		return $path;
	}

	/**
	 * Whether a path is a file PHP received in this request's upload.
	 */
	protected function isUpload(string $path): bool {
		return is_uploaded_file($path);
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
