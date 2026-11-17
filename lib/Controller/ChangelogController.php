<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Controller;

use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Release\NotesTranslationRequest;
use OCA\Versioniq\Service\Release\NotesTranslator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Translates a version's release notes on request (releases-notes-reach D3).
 * Admin-only twice over: the route carries no NoAdminRequired attribute, so
 * Nextcloud's middleware already refuses non-admins, and isAdmin() below is
 * the explicit guard the unit tests pin.
 *
 * @psalm-suppress UnusedClass
 */
class ChangelogController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private NotesTranslator $notesTranslator,
		private ApiCaller $caller,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Translates the release notes of one version into a language
	 *
	 * @param string $appId The app whose version it is
	 * @param string $version The version whose notes to translate
	 * @param string $language The language to translate into, such as nl
	 * @param string|null $source A source override, as the version listing takes it
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_BAD_GATEWAY, array<string, mixed>, array{}>
	 *
	 * 200: The translation, labelled as a machine translation
	 * 400: No version or no valid language was named, or the source is invalid
	 * 403: Caller is not an administrator
	 * 404: The version has no release notes
	 * 409: The instance has no machine translation provider
	 * 502: The source or the translation provider failed
	 *
	 * @psalm-suppress InvalidReturnType, InvalidReturnStatement The status is the translator's, one of those documented
	 *
	 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
	 */
	#[ApiRoute(verb: 'POST', url: '/api/changelog/translate')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/changelog/translate', postfix: 'v1')]
	public function translate(string $appId = '', string $version = '', string $language = '', ?string $source = null): DataResponse {
		$uid = $this->caller->adminUid();
		if ($uid === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$override = ($source !== null && trim($source) !== '') ? trim($source) : null;
		$result = $this->notesTranslator->translate(new NotesTranslationRequest(trim($appId), trim($version), $language, $override, $uid));

		return new DataResponse($result['payload'], $result['statusCode']);
	}
}
