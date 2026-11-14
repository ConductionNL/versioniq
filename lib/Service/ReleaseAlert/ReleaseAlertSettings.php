<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\ReleaseAlert;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\IAppConfig;

/**
 * Which new releases reach the admins' bell: `forge` (the default: apps
 * whose source is not the App Store), `all`, or `off`
 * (releases-new-release-alerts, design D2).
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
 *
 * @psalm-import-type ReleaseEvent from ReleaseEventDetector
 */
class ReleaseAlertSettings {
	public const KEY_SCOPE = 'release_alerts.scope';

	public const SCOPE_FORGE = 'forge';

	public const SCOPE_ALL = 'all';

	public const SCOPE_OFF = 'off';

	private const SCOPES = [self::SCOPE_FORGE, self::SCOPE_ALL, self::SCOPE_OFF];

	public function __construct(
		private IAppConfig $config,
		private ReleaseEventLog $log,
		private AuditLogger $auditLogger,
	) {
	}

	/**
	 * Adds detected releases to the log.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
	 * @param list<ReleaseEvent> $events
	 */
	public function logReleases(array $events): void {
		$this->log->append($events);
	}

	/**
	 * The logged releases, newest first, for the Alerts tab.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
	 * @return list<ReleaseEvent>
	 */
	public function releases(): array {
		return $this->log->all();
	}

	/**
	 * The stored scope; anything unknown reads as the default.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 */
	public function scope(): string {
		$scope = $this->config->getValueString(Application::APP_ID, self::KEY_SCOPE, self::SCOPE_FORGE);

		return in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_FORGE;
	}

	/**
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 * Stores the scope and records the change in the audit trail under the admin who made it.
	 *
	 * @throws InvalidArgumentException For a scope that is not forge, all or off.
	 */
	public function setScope(string $scope, string $uid): void {
		if (!in_array($scope, self::SCOPES, true)) {
			throw new InvalidArgumentException('The scope must be forge, all or off.');
		}

		$this->config->setValueString(Application::APP_ID, self::KEY_SCOPE, $scope);
		$this->auditLogger->record(
			new AuditSubject($uid, Application::APP_ID, AuditLogger::OPERATION_SETTINGS),
			new AuditedVersions(null, null, null),
			AuditLogger::STATUS_SUCCESS,
			'Settings changed: ' . self::KEY_SCOPE . '=' . $scope,
		);
	}

	/**
	 * Whether a release is announced under the stored scope. An app without a
	 * source, or from the App Store, is left to Nextcloud's own update
	 * notification unless the scope is `all`.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 * @param ReleaseEvent $event
	 */
	public function announces(array $event): bool {
		return match ($this->scope()) {
			self::SCOPE_ALL => true,
			self::SCOPE_OFF => false,
			default => !in_array($event['sourceId'] ?? null, [null, '', 'appstore'], true),
		};
	}
}
