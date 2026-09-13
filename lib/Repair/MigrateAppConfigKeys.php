<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Repair;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Carries this app's stored `IAppConfig` values across the `app_versions` ->
 * `versioniq` app-id rename.
 *
 * Nextcloud namespaces `IAppConfig` by app id at the storage layer
 * (`oc_appconfig.appid`), so renaming `<id>` does not rename the rows — it
 * makes every previously stored value unreachable, because the app now asks
 * for them under a different app id. There is no in-place app-id upgrade in
 * Nextcloud: the new id is simply a different app. This step therefore copies
 * each value from the old namespace to the new one.
 *
 * WHY THIS MATTERS PARTICULARLY HERE. Almost everything this app remembers
 * lives in `oc_appconfig`, and every reader carries a default, so a lost value
 * does not error — it silently reverts to the shipped behaviour:
 *   - `trusted_sources` is the ALLOWLIST of forges and owners an external
 *     install may come from ({@see \OCA\Versioniq\Service\Source\TrustedSourceList}).
 *     Losing it does not block installs, it reverts the allowlist to the
 *     shipped default, quietly widening or narrowing what an admin decided.
 *   - `pin.{appId}` is every version pin
 *     ({@see \OCA\Versioniq\Service\Pin\PinStore}). Losing a pin does not warn;
 *     it just stops holding the app back, and the next auto-update sweep moves
 *     an app the admin deliberately froze.
 *   - `policy.{appId}` is each app's auto-update policy
 *     ({@see \OCA\Versioniq\Service\Policy\PolicyStore}) and `auto_update.*`
 *     the global enable plus its maintenance window. A dropped `none` policy
 *     reverts to "follow the global setting" — the opposite of what was set.
 *   - `source.{appId}` is the binding that says an app is installed from a
 *     GitHub/Codeberg release rather than the App Store
 *     ({@see \OCA\Versioniq\Service\Source\SourceBindingStore}). Losing it
 *     silently sends the next update back to the App Store.
 *   - `advisory.*`, `audit_retention_days`, `discovery.github_search_enabled`,
 *     `cache.keep` and the `appstore.api_base` / `forge.*` overrides are
 *     admin-chosen operational settings with the same silent-revert shape.
 *
 * WHAT THIS STEP DOES NOT TOUCH.
 *   - The `versioniq_pats` and `versioniq_audit` TABLES. Table names are
 *     not keyed by app id, so the rename never reached them; see the comments
 *     on {@see \OCA\Versioniq\Db\PatMapper::TABLE_NAME} and
 *     {@see \OCA\Versioniq\Db\AuditEntryMapper::TABLE_NAME}. Every stored PAT
 *     and every audit row is already where the new code looks for it.
 *   - `oc_activity` / `oc_notifications` rows written under the old app id.
 *     Nextcloud owns those tables and exposes no supported way to re-key them;
 *     historic entries simply stop being listed.
 *
 * WHY EVERY KEY RATHER THAN A FIXED LIST. Most of this app's keys are
 * PER-MANAGED-APP and therefore unbounded — `pin.openregister`,
 * `policy.hermiq`, `source.<whatever the admin installed>`, plus the App Store
 * payload cache's `appstore.payload.*` entries. No hardcoded list can name
 * them. Enumerating `IAppConfig::getKeys()` is exhaustive by construction and
 * cannot drift when a future release adds a key.
 *
 * SAFETY. Idempotent and non-destructive:
 *   - a key is copied only when the old value is non-empty AND the new
 *     namespace does not already hold a value, so an admin edit made after the
 *     rename is never clobbered and a second run is a no-op;
 *   - the old `app_versions` rows are never deleted, so a rollback to the
 *     previous app id still finds its configuration intact;
 *   - values round-trip as raw strings. `IAppConfig` stores every value as a
 *     string and the typed accessors only coerce on read, so a string
 *     round-trip cannot lose or corrupt a value written by a typed setter;
 *   - every failure is logged and the loop continues, INCLUDING the reads. A
 *     repair step that throws aborts the install, and because this step is
 *     registered under `<install>` an aborted run means the app never enables
 *     and every route goes with it. One unreadable key is not worth that.
 *
 * Registered FIRST under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml` — see the ordering comment there.
 *
 * @psalm-api
 */
class MigrateAppConfigKeys implements IRepairStep {
	/**
	 * The app-config namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id. This constant is one of the few places in
	 * the app that is supposed to still say `app_versions`.
	 *
	 * @var string
	 */
	// gate-59 (unclosable-gate) reads this as a config GATE that is read and
	// never written, and on the usual shape it would be right: a flag nothing
	// persists means the setup it guards re-runs forever. Here the never-write
	// is the entire safety property. This is the app id of the PREVIOUS app,
	// and the two Migrate* steps read the old namespace and write only the new
	// one, so the old rows survive a rollback. Writing anything back under it
	// would make the migration destructive — the opposite of closing a gate.
	// The steps are idempotent through their own "already present under the
	// new id" check, which is what actually stops repeated work.
	// unclosable-gate exclude 'app_versions' is the pre-rename app id: read-only by design, see above.
	private const OLD_APP_ID = 'app_versions';

	/**
	 * Config keys Nextcloud owns for every app. These MUST NOT be copied.
	 *
	 * `AppManager::enableApp()` writes `enabled` through the deprecated
	 * `IAppConfig::setValue()`, which stores type MIXED. Copying it here with
	 * `setValueString()` stores type STRING, and the next `app:enable` then
	 * fails with an `AppConfigTypeConflictException` — permanently, because the
	 * conflict is hit before the app can run anything that would repair it.
	 * `installed_version` and `types` are Nextcloud's own bookkeeping for the
	 * app, and copying the old app's values would misreport the new one.
	 *
	 * @var string[]
	 */
	private const RESERVED_KEYS = [
		'enabled',
		'installed_version',
		'types',
	];

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Human-readable name shown by `occ upgrade` / `occ maintenance:repair`.
	 *
	 * @spec exclude One-off app_versions->versioniq rename plumbing; the
	 *       IRepairStep contract requires a name and it carries no behaviour.
	 */
	public function getName(): string {
		return 'Copy Versioniq app configuration from the app_versions namespace to versioniq';
	}

	/**
	 * Copy every stored app-config value from the old app id to the new one.
	 *
	 * @spec exclude One-off app_versions->versioniq app-id rename plumbing: it
	 *       moves oc_appconfig rows between namespaces and adds no behaviour of
	 *       its own. The settings it preserves are specified where they are
	 *       read — trusted_sources and source.{appId} in
	 *       openspec/specs/external-sources/spec.md, pin.{appId} in
	 *       openspec/specs/version-pinning/spec.md, policy.{appId} and
	 *       auto_update.* in openspec/specs/auto-update-policies/spec.md,
	 *       audit_retention_days in openspec/specs/audit-trail/spec.md,
	 *       discovery.github_search_enabled in
	 *       openspec/specs/app-discovery/spec.md and cache.keep in
	 *       openspec/specs/artifact-cache/spec.md.
	 */
	public function run(IOutput $output): void {
		$keys = $this->oldKeys();
		if ($keys === []) {
			$output->info('MigrateAppConfigKeys: no stored app_versions configuration on this install; nothing to do.');
			return;
		}

		$migrated = 0;
		$alreadyPresent = 0;
		$emptySource = 0;
		$skippedReserved = 0;

		foreach ($keys as $key) {
			if (in_array($key, self::RESERVED_KEYS, true) === true) {
				$skippedReserved++;
				continue;
			}

			/* Both READS belong inside the try as much as the write does. A
			   read that throws from outside it propagates out of run() and
			   aborts `occ upgrade` — and because this step also runs under
			   <install>, an app that cannot finish its repair steps does not
			   enable at all, taking every route with it. That is the opposite
			   of what this class's docblock promises. */
			try {
				$old = $this->appConfig->getValueString(self::OLD_APP_ID, $key, '');
				if ($old === '') {
					$emptySource++;
					continue;
				}

				$existing = $this->appConfig->getValueString(Application::APP_ID, $key, '');
				if ($existing !== '') {
					$alreadyPresent++;
					continue;
				}

				$this->appConfig->setValueString(Application::APP_ID, $key, $old);
				$migrated++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Versioniq: could not migrate one app config key; leaving it under the old namespace',
					['key' => $key, 'exception' => $e->getMessage()],
				);
			}
		}

		$output->info(
			'MigrateAppConfigKeys: ' . $migrated . ' key(s) migrated, ' . $alreadyPresent
			. ' already present, ' . $emptySource . ' had no value to migrate, '
			. $skippedReserved . ' skipped as Nextcloud-reserved.',
		);
	}

	/**
	 * Every key currently stored under the old app-config namespace.
	 *
	 * @return array<int, string> The stored key names, empty when unreadable.
	 */
	private function oldKeys(): array {
		try {
			return $this->appConfig->getKeys(self::OLD_APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Versioniq: could not enumerate app_versions app config keys; skipping the migration',
				['exception' => $e->getMessage()],
			);
			return [];
		}
	}
}
