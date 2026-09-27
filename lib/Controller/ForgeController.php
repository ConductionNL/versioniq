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
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads and sets the self-hosted Forgejo or Gitea host (issue #437). Before
 * this, another Forgejo or Gitea host was reachable only by overriding
 * `forge.codeberg.api_base` with occ, which replaced Codeberg; Codeberg is now
 * retired as a forge of its own and this host is the generic route.
 *
 * Admin-only twice over: the routes carry no NoAdminRequired attribute, so
 * Nextcloud's middleware already refuses non-admins, and isAdmin() below is
 * the explicit guard the unit tests pin.
 *
 * @psalm-suppress UnusedClass
 */
class ForgeController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ForgeRegistry $forgeRegistry,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Returns the self-hosted Forgejo or Gitea host
	 *
	 * @return DataResponse<Http::STATUS_OK, array{host: string, configured: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The host, empty when none is configured
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/forges/forgejo')]
	public function forgejoHost(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse($this->hostPayload());
	}

	/**
	 * Sets or clears the self-hosted Forgejo or Gitea host
	 *
	 * @param string $host An https address such as https://git.example.org; empty clears it
	 *
	 * @return DataResponse<Http::STATUS_OK, array{host: string, configured: bool}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The host after the change
	 * 400: The host is not an acceptable https address
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/forges/forgejo')]
	public function setForgejoHost(string $host = ''): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->forgeRegistry->setForgejoHost($host);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->hostPayload());
	}

	/**
	 * @return array{host: string, configured: bool}
	 */
	private function hostPayload(): array {
		$host = $this->forgeRegistry->forgejoHost();

		return ['host' => $host, 'configured' => $host !== ''];
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
