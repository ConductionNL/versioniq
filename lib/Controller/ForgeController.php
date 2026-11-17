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
use OCA\Versioniq\Kernel\Contracts\ForgeRegistryInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads and sets the self-hosted Forgejo or Gitea host (issue #437), and the
 * GitLab host (gitlab.com or a self-managed one, sources-gitlab-forge D2). Before
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
	use ChecksAdmin;

	public function __construct(
		string $appName,
		IRequest $request,
		private ForgeRegistryInterface $forgeRegistry,
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
	#[ApiRoute(verb: 'GET', url: '/api/v1/forges/forgejo', postfix: 'v1')]
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
	#[ApiRoute(verb: 'PUT', url: '/api/v1/forges/forgejo', postfix: 'v1')]
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
	 * Returns the GitLab host releases come from
	 *
	 * @return DataResponse<Http::STATUS_OK, array{host: string, selfManaged: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The host: gitlab.com, or the self-managed host when one is set
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-4.1
	 */
	#[ApiRoute(verb: 'GET', url: '/api/forges/gitlab')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/forges/gitlab', postfix: 'v1')]
	public function gitlabHost(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse($this->gitlabPayload());
	}

	/**
	 * Points GitLab at a self-managed host, or back at gitlab.com
	 *
	 * @param string $host An https address such as https://gitlab.example.org; empty means gitlab.com
	 *
	 * @return DataResponse<Http::STATUS_OK, array{host: string, selfManaged: bool}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The host after the change
	 * 400: The host is not an acceptable https address
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-4.1
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/forges/gitlab')]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/forges/gitlab', postfix: 'v1')]
	public function setGitlabHost(string $host = ''): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->forgeRegistry->setGitlabHost($host);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->gitlabPayload());
	}

	/**
	 * @return array{host: string, selfManaged: bool}
	 */
	private function gitlabPayload(): array {
		$host = $this->forgeRegistry->gitlabHost();

		return ['host' => $host, 'selfManaged' => $host !== ForgeRegistryInterface::GITLAB_WEB_DEFAULT];
	}

	/**
	 * @return array{host: string, configured: bool}
	 */
	private function hostPayload(): array {
		$host = $this->forgeRegistry->forgejoHost();

		return ['host' => $host, 'configured' => $host !== ''];
	}
}
