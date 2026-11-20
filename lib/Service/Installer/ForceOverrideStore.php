<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Settings\AppConfigJsonRecords;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * The platform's `app_install_overwrite` list as Versioniq uses it: an app on
 * that list is installed and enabled past its declared maximum. Versioniq adds
 * an app only for an admin's "Install anyway" or "Enable anyway", removes only
 * the entries it added, and keeps a record per override it set
 * (`force_incompatible.{appId}`) so it can lift it at the next major.
 *
 * Each change reads the list and writes it back in one step, changing only its
 * own app id, so an entry another writer added in between is kept.
 *
 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
 */
class ForceOverrideStore {
	/** @use AppConfigJsonRecords<ForceOverride> */
	use AppConfigJsonRecords;

	public const SYSTEM_KEY = 'app_install_overwrite';

	private const KEY_PREFIX = 'force_incompatible.';
	private const JSON_DEPTH = 8;
	private const MALFORMED_LOG = 'ForceOverrideStore: malformed record JSON, treating as no record';
	private const INVALID_LOG = 'ForceOverrideStore: invalid record payload, treating as no record';

	public function __construct(
		private IConfig $systemConfig,
		private IAppConfig $config,
		private AuditLogger $auditLogger,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the app is on `app_install_overwrite`, whoever put it there.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public function isOverridden(string $appId): bool {
		return in_array($appId, $this->overwriteList(), true);
	}

	/**
	 * Adds the app to `app_install_overwrite` when it is not on it. Returns
	 * whether Versioniq added it, which is what decides whether a failure
	 * removes it again.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public function apply(string $appId): bool {
		$list = $this->overwriteList();
		if (in_array($appId, $list, true)) {
			return false;
		}
		$list[] = $appId;
		$this->systemConfig->setSystemValue(self::SYSTEM_KEY, $list);

		return true;
	}

	/**
	 * Removes the app from `app_install_overwrite`, keeping every other entry
	 * as the list holds it now. Call it only for an entry Versioniq added.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public function remove(string $appId): void {
		$list = $this->overwriteList();
		if (!in_array($appId, $list, true)) {
			return;
		}
		$kept = array_values(array_filter($list, static fn (string $entry): bool => $entry !== $appId));
		if ($kept === []) {
			$this->systemConfig->deleteSystemValue(self::SYSTEM_KEY);

			return;
		}
		$this->systemConfig->setSystemValue(self::SYSTEM_KEY, $kept);
	}

	/**
	 * Records an override after a successful forced install or enable and
	 * writes the `force_incompatible` audit row.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public function record(string $appId, string $version, int $serverMajor, string $actorUid, bool $addedByVersioniq): void {
		$override = new ForceOverride(
			$version,
			$serverMajor,
			$actorUid,
			$this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DATE_ATOM),
			$addedByVersioniq,
		);
		$this->writeRecord($appId, $override->toArray());
		$this->auditLogger->record(
			new AuditSubject($actorUid, $appId, AuditLogger::OPERATION_FORCE_INCOMPATIBLE),
			new AuditedVersions(null, $version, null),
			AuditLogger::STATUS_SUCCESS,
			'Forced past its declared maximum on Nextcloud ' . $serverMajor . '.',
		);
	}

	/**
	 * The record of the override Versioniq set for the app, or null.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public function get(string $appId): ?ForceOverride {
		return $this->readRecord($appId);
	}

	/**
	 * Every override Versioniq set, keyed by app id.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 * @return array<string, ForceOverride>
	 */
	public function all(): array {
		return $this->readAllRecords();
	}

	/**
	 * Drops the record of an override; the list itself is not touched.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public function forget(string $appId): void {
		$this->config->deleteKey(Application::APP_ID, $this->key($appId));
	}

	/**
	 * The app ids on `app_install_overwrite`; anything that is not a list of
	 * strings reads as the strings it holds.
	 *
	 * @return list<string>
	 */
	private function overwriteList(): array {
		/** @var mixed $list */
		$list = $this->systemConfig->getSystemValue(self::SYSTEM_KEY, []);
		if (!is_array($list)) {
			return [];
		}

		return array_values(array_filter($list, 'is_string'));
	}

	/**
	 * @param array<array-key, mixed> $payload
	 */
	private function hydrate(array $payload): ForceOverride {
		return ForceOverride::fromArray($payload);
	}
}
