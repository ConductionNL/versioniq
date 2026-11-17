<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Policy;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Export and import of the policy file: what the stores hold, what a file
 * would change, and applying it through the same stores as the API, with one
 * audit row per changed policy and `setBy` `occ`.
 *
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 * @psalm-api
 */
class PolicyFileSync {
	/** The `setBy` of every policy an import writes. */
	public const SET_BY = 'occ';

	public function __construct(
		private PolicyStore $policyStore,
		private AutoUpdateSettingsStore $settingsStore,
		private AuditLogger $auditLogger,
		private ITimeFactory $time,
	) {
	}

	/**
	 * The stored settings and policies, in the file's shape.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	public function export(): PolicyFileContents {
		return PolicyFileContents::fromStores($this->policyStore, $this->settingsStore);
	}

	/**
	 * What applying the file would change, settings first, then policies by
	 * app id. Settings and policies the file does not name stay as they are,
	 * except that `$prune` removes stored policies the file does not name.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 * @return list<PolicyChange>
	 */
	public function plan(PolicyFileContents $file, bool $prune): array {
		$stored = $this->export();
		// With prune, every stored policy the file does not name is wanted gone (null).
		$wanted = $prune ? $file->policies + array_fill_keys(array_keys($stored->policies), null) : $file->policies;

		return [...self::settingChanges($stored, $file), ...self::policyChanges($stored->policies, $wanted)];
	}

	/**
	 * The settings the file names with another value than the stored one.
	 *
	 * @return list<PolicyChange>
	 */
	private static function settingChanges(PolicyFileContents $stored, PolicyFileContents $file): array {
		$changes = [];
		foreach (self::settingPairs($stored, $file) as $key => [$from, $to]) {
			if ($to !== null && $to !== $from) {
				$changes[] = new PolicyChange($key, $from, $to);
			}
		}

		return $changes;
	}

	/**
	 * The per-app policies whose wanted level (null: removed) differs from the stored one, by app id.
	 *
	 * @param array<string, string>      $stored The stored level per app.
	 * @param array<string, string|null> $wanted The wanted level per app.
	 * @return list<PolicyChange>
	 */
	private static function policyChanges(array $stored, array $wanted): array {
		ksort($wanted);
		$changes = [];
		foreach ($wanted as $appId => $to) {
			$from = $stored[$appId] ?? null;
			if ($to !== $from) {
				$changes[] = new PolicyChange('policies.' . $appId, $from, $to);
			}
		}

		return $changes;
	}

	/**
	 * Writes the changes of {@see plan()} and records one audit row per changed policy.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 * @param list<PolicyChange> $changes
	 */
	public function apply(array $changes): void {
		$at = $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
		foreach ($changes as $change) {
			$appId = $change->appId();
			if ($appId === null) {
				$this->applySetting($change);
				continue;
			}

			if ($change->to === null) {
				$this->policyStore->clear($appId);
			} else {
				$this->policyStore->set($appId, new Policy($change->to, self::SET_BY, $at));
			}
			$this->auditLogger->record(
				new AuditSubject(self::SET_BY, $appId, AuditLogger::OPERATION_POLICY, AuditOrigin::CLI),
				new AuditedVersions($change->from, $change->to, null),
				AuditLogger::STATUS_SUCCESS,
			);
		}
	}

	private function applySetting(PolicyChange $change): void {
		$value = (string)$change->to;
		match ($change->key) {
			'autoUpdate.enabled' => $this->settingsStore->setEnabled($value === 'true'),
			'autoUpdate.window' => $this->settingsStore->setWindow($value),
			default => $this->settingsStore->setDefaultLevel($value),
		};
	}

	/**
	 * The three settings as [stored, file] string pairs; null where unnamed.
	 *
	 * @return array<string, array{0: ?string, 1: ?string}>
	 */
	private static function settingPairs(PolicyFileContents $stored, PolicyFileContents $file): array {
		return [
			'autoUpdate.enabled' => [self::flag($stored->enabled), self::flag($file->enabled)],
			'autoUpdate.window' => [$stored->window, $file->window],
			'autoUpdate.defaultLevel' => [$stored->defaultLevel, $file->defaultLevel],
		];
	}

	private static function flag(?bool $value): ?string {
		return $value === null ? null : ($value ? 'true' : 'false');
	}
}
