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
use OCA\Versioniq\Service\Installer\StoreSignatureCheck;
use OCA\Versioniq\Service\Installer\UploadedArchive;
use OCA\Versioniq\Service\InstallerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Install from a file: read an uploaded app archive and show what it is before
 * anything is installed (install-manual-upload D1), then install it by the
 * token that step returned (D2).
 *
 * Admin-only twice over, like SettingsController: no NoAdminRequired
 * attribute, and the explicit isAdmin() guard. Uploading code is a strict
 * password-confirmed action.
 *
 * @psalm-suppress UnusedClass
 */
class UploadController extends OCSController {
	use ChecksAdmin;

	/** The multipart field that carries the archive. */
	public const FIELD = 'archive';

	public function __construct(
		string $appName,
		IRequest $request,
		private UploadedArchive $uploads,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private StoreSignatureCheck $signatures,
		private InstallerService $installer,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Reads an uploaded app archive (multipart field `archive`, a `.tar.gz` of at most 256 MB) and keeps it for an hour; installs nothing
	 *
	 * @return DataResponse<Http::STATUS_OK, array{token: string, appId: string, version: string, sha256: string, signedByStore: bool}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: The app id and version the archive's info.xml declares, its SHA-256, whether the App Store's signature verifies it, and the token to install it with
	 * 400: No archive was uploaded, or the upload failed
	 * 403: Caller is not an administrator
	 * 422: The archive is too large, not a .tar.gz, or has no usable appinfo/info.xml
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.1
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.2
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

		$inspected['signedByStore'] = $this->signatures->storeRelease($inspected['appId'], $inspected['version'], $path) !== null;

		return new DataResponse($inspected);
	}

	/**
	 * Installs an inspected upload: the guards of a normal install, then the App Store's signature, or for an unsigned archive the setting and the SHA-256 the admin pasted or accepted
	 *
	 * @param string $token The token the inspect step returned
	 * @param string $expectedSha256 The SHA-256 the admin expects; blank when none was pasted
	 * @param bool $acceptShown Whether the admin accepts the SHA-256 shown for an unsigned archive
	 * @param bool $allowDowngrade Acknowledges installing a version older than the installed one
	 * @param string|null $overridePin `repin` or `unpin` to install over a pinned version
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The installer's outcome with source `upload`, the SHA-256 and whether the App Store signed the archive. The status is the outcome's own: 400 for an unsigned archive without a pasted or accepted SHA-256, 403 when unsigned uploads are off, 404 for an unknown or expired upload, 409 for a pin, an unacknowledged downgrade or a SHA-256 that is not the expected one, 4xx or 5xx for a failed install
	 * 403: Caller is not an administrator
	 *
	 * As for installVersion(), the @return above is the documented contract; the
	 * runtime status is the installer's, which psalm cannot narrow.
	 *
	 * @psalm-suppress InvalidReturnType
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.1
	 */
	#[PasswordConfirmationRequired(strict: true)]
	#[ApiRoute(verb: 'POST', url: '/api/upload/{token}/install')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/upload/{token}/install', postfix: 'v1')]
	public function install(string $token, string $expectedSha256 = '', bool $acceptShown = false, bool $allowDowngrade = false, ?string $overridePin = null): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$outcome = $this->installer->installUploadedArchive($token, $expectedSha256, $acceptShown, $allowDowngrade, $overridePin);

		/** @psalm-suppress InvalidReturnStatement Status passthrough, see the docblock. */
		return new DataResponse($outcome['payload'], $outcome['statusCode']);
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
}
