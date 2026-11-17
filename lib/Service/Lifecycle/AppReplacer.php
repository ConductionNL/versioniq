<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Lifecycle;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\InstallerService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;

/**
 * Moves an install from an app to its listed successor (design D5).
 *
 * The successor is installed first, through the normal install path (which
 * writes its own `install` audit row and binds the source on success). Only
 * after that install succeeded is the successor enabled and the old app
 * disabled, with a `replace` audit row for the old app. The old app's data is
 * never touched: moving it is the successor's own migration.
 *
 * @psalm-api
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
 */
class AppReplacer {
	/** The audit operation of the old app's step. */
	public const OPERATION_REPLACE = 'replace';

	public function __construct(
		private SuccessorList $successors,
		private InstallerService $installer,
		private IAppManager $appManager,
		private AuditLogger $audit,
	) {
	}

	/**
	 * Replaces an app with the successor the list offers for it.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 * @param AuditSubject $subject The admin who asked (`actorUid`) and the app to replace (`appId`).
	 * @param string $to The successor the admin confirmed; it must be the one offered.
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	public function replace(AuditSubject $subject, string $to): array {
		$offer = $this->successors->offerFor($subject->appId);
		if ($offer === null || $offer['to'] !== $to) {
			return self::outcome(Http::STATUS_NOT_FOUND, ['replaced' => false, 'message' => 'No successor this server can run is listed for this app.']);
		}

		$install = $this->installer->installAppVersion(new InstallRequest(
			new InstallTarget($offer['to'], $offer['version'], $offer['source']),
			new InstallOptions(includeDebug: false, dryRun: false, acceptNewSha: false, allowDowngrade: false),
			new PinChoice(overridePin: null, pinRequested: false),
		));
		if ($install['statusCode'] !== Http::STATUS_OK) {
			return self::outcome($install['statusCode'], [
				'replaced' => false,
				'message' => 'The successor could not be installed; the old app stays enabled.',
				'install' => $install['payload'],
			]);
		}

		$outcome = $this->switchOver(new AuditSubject($subject->actorUid, $subject->appId, self::OPERATION_REPLACE), $offer);
		$outcome['payload']['install'] = $install['payload'];

		return $outcome;
	}

	/**
	 * Enables the installed successor, then disables the old app, and audits the step.
	 * The caller adds the install payload under `install`.
	 *
	 * @param AuditSubject $subject The `replace` audit subject of the old app.
	 * @param array{from: string, to: string, source: string, custom: bool, version: string} $offer
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	private function switchOver(AuditSubject $subject, array $offer): array {
		$appId = $subject->appId;
		$versions = new AuditedVersions($this->appManager->getAppVersion($appId), null, $offer['source']);
		try {
			$this->appManager->enableApp($offer['to']);
			$this->appManager->disableApp($appId);
		} catch (\Throwable $error) {
			$this->audit->record($subject, $versions, AuditLogger::STATUS_FAILURE, 'Replacing with ' . $offer['to'] . ' failed: ' . $error->getMessage());

			return self::outcome(Http::STATUS_INTERNAL_SERVER_ERROR, [
				'replaced' => false,
				'message' => 'The successor was installed, but switching over failed: ' . $error->getMessage(),
			]);
		}
		$this->audit->record($subject, $versions, AuditLogger::STATUS_SUCCESS, 'Replaced with ' . $offer['to'] . ' ' . $offer['version'] . '; its data was kept.');

		return self::outcome(Http::STATUS_OK, [
			'replaced' => true,
			'from' => $appId,
			'to' => $offer['to'],
			'version' => $offer['version'],
		]);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	private static function outcome(int $statusCode, array $payload): array {
		return ['statusCode' => $statusCode, 'payload' => $payload];
	}
}
