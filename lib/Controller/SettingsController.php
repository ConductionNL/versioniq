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
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads and sets the instance settings that used to be occ-only (#438 item
 * 7): audit retention, artifact cache size, App Store and GitHub base URLs
 * and the advisory feed URL.
 *
 * Admin-only twice over, like ForgeController: no NoAdminRequired attribute,
 * and the explicit isAdmin() guard the unit tests pin. A change is audited,
 * because pointing the App Store or GitHub at another host changes where
 * installs come from.
 *
 * @psalm-suppress UnusedClass
 */
class SettingsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private InstanceSettings $settings,
		private AuditLogger $auditLogger,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Returns the instance settings with their defaults
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, int|string>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The settings, each override ('' when unset) next to its default
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/instance-settings')]
	public function instanceSettings(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse($this->settings->read());
	}

	/**
	 * Updates the instance settings (password-confirmed); an omitted field is left alone
	 *
	 * @param string|null $auditRetentionDays Days of history to keep, 30 to 3650
	 * @param string|null $artifactCacheKeep Archives kept per app, 0 to 20 (0 turns the cache off)
	 * @param string|null $appStoreApiBase App Store API base URL; blank uses the public store
	 * @param string|null $githubApiBase GitHub API base URL (https); blank uses api.github.com
	 * @param string|null $githubWebBase GitHub web base URL (https); blank uses github.com
	 * @param string|null $advisoryFeedUrl Advisory feed URL; blank uses the published Nextcloud feed
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, int|string>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The settings after the change
	 * 400: A value is out of range or not an acceptable URL; nothing was changed
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/instance-settings')]
	public function updateInstanceSettings(
		?string $auditRetentionDays = null,
		?string $artifactCacheKeep = null,
		?string $appStoreApiBase = null,
		?string $githubApiBase = null,
		?string $githubWebBase = null,
		?string $advisoryFeedUrl = null,
	): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$fields = array_filter([
			'auditRetentionDays' => $auditRetentionDays,
			'artifactCacheKeep' => $artifactCacheKeep,
			'appStoreApiBase' => $appStoreApiBase,
			'githubApiBase' => $githubApiBase,
			'githubWebBase' => $githubWebBase,
			'advisoryFeedUrl' => $advisoryFeedUrl,
		], static fn (?string $value): bool => $value !== null);

		try {
			$applied = $this->settings->update($fields);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$after = $this->settings->read();
		if ($applied !== []) {
			$this->auditLogger->record(
				$this->userSession->getUser()?->getUID() ?? '',
				'versioniq',
				AuditLogger::OPERATION_SETTINGS,
				null,
				null,
				null,
				AuditLogger::STATUS_SUCCESS,
				'Settings changed: ' . implode(', ', array_map(
					static fn (string $field): string => $field . '=' . (string)$after[$field],
					$applied,
				)),
			);
		}

		return new DataResponse($after);
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
