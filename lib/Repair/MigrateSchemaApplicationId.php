<?php

/**
 * Re-points this app's OpenRegister SCHEMAS at the new application id.
 *
 * A REGISTER-SLUG RENAME WOULD NOT COVER THIS, AND THIS APP DOES NOT DO ONE.
 * OpenRegister resolves the two halves of a configuration by DIFFERENT keys:
 *
 *   - a REGISTER by slug alone      -> RegisterMapper::find(slug)
 *   - a SCHEMA by the PAIR          -> SchemaMapper::findByApplicationAndSlug(slug, application)
 *
 * An import passes `appId: Application::APP_ID`, which is now `versioniq`.
 * Every schema written under the old id still carries
 * `application = 'app_versions'`, so the pair matches nothing — and
 * ImportHandler's not-found branch is not an error path, it is the "create a
 * new one" path. The next import therefore builds a SECOND, EMPTY set of
 * schemas under the new application id while every stored object stays bound
 * to the old ones. Nothing errors. The app renders empty collections.
 *
 * `app_versions`, NOT `app-versions`. The hyphen is the GitHub REPOSITORY
 * name; the app id — the value `openregister_schemas.application` actually
 * holds — is the underscore form, as `<id>` carried before the rename and as
 * MigrateAppConfigKeys and MigrateUserPreferences already use. Written with
 * the hyphen this step would match nothing and report success forever.
 *
 * THIS APP DOES NOT SHIP A REGISTER OF ITS OWN TODAY: its versions, PATs and
 * audit trail live in `versioniq_*` tables it owns directly. So on a clean
 * instance there is nothing under `app_versions` in OpenRegister and this step
 * reports "nothing to do" and writes nothing. It ships as the guard for an
 * instance that DOES carry such rows, because there the failure is silent.
 *
 * The gap is fleet-wide, and measured: on a live install 231 schemas still sat
 * under `docudesk`, 200 under `openconnector`, 180 under `procest`, 98 under
 * `decidesk`, 21 under `softwarecatalog`, 17 under `openbuild`. `app_versions`
 * did NOT appear in that measurement — no number is claimed for this app that
 * was not measured.
 *
 * IT REFUSES RATHER THAN MERGES. Where a schema slug ALREADY has a twin under
 * the new application id, the fork has happened: re-pointing would leave two
 * rows sharing (application, slug), and findByApplicationAndSlug() caps at one
 * row — so one of them silently wins every lookup and the other's objects
 * become unreachable. Choosing between them is a decision about data, not a
 * migration, so this step logs and leaves both alone. Measured: integriq has
 * 200 such collisions, planninq and larpinq 2 each.
 *
 * IT NEVER DELETES A SCHEMA, and it never throws — it runs under `<install>`,
 * where an escaping exception aborts the install and the app never enables.
 *
 * @category  Repair
 * @package   OCA\Versioniq\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Versioniq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Moves this app's schema rows onto the new application id.
 *
 * Registered under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml`, which is a STRING reference Psalm cannot follow — hence
 * `@psalm-api`, the same annotation the sibling Migrate* steps carry.
 *
 * @spec exclude No canonical spec covers the `app_versions` -> `versioniq` schema
 *  application-id migration. Pointing this at an existing spec would report
 *  conformance to a requirement that says nothing about it.
 *
 * @psalm-api
 */
class MigrateSchemaApplicationId implements IRepairStep {

	/**
	 * The application id these schemas were written under.
	 *
	 * @var string
	 */
	public const OLD_APP_ID = 'app_versions';

	/**
	 * The application id the import now looks them up by.
	 *
	 * @var string
	 */
	public const NEW_APP_ID = 'versioniq';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec exclude No canonical spec covers the `app_versions` -> `versioniq` schema
	 *  application-id migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the `app_versions` -> `versioniq` schema
	 *  application-id migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Move Versioniq schemas onto the versioniq application id';
	}//end getName()

	/**
	 * Re-point every schema that has no twin under the new application id.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the `app_versions` -> `versioniq` schema
	 *  application-id migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function run(IOutput $output): void {
		$taken = $this->slugsUnderNewAppId();
		if ($taken === null) {
			$output->info('MigrateSchemaApplicationId: could not read schemas; nothing done.');
			return;
		}

		$candidates = $this->slugsUnderOldAppId();
		if ($candidates === []) {
			$output->info('MigrateSchemaApplicationId: no schemas on the old application id; nothing to do.');
			return;
		}

		$moved = 0;
		$refused = 0;
		foreach ($candidates as $slug) {
			if (in_array(strtolower($slug), $taken, true) === true) {
				$refused++;
				$this->logger->warning(
					'MigrateSchemaApplicationId: schema slug already exists under the new application id; '
					. 'refusing to create a duplicate (application, slug) pair. Merge them by hand.',
					['slug' => $slug, 'old' => self::OLD_APP_ID, 'new' => self::NEW_APP_ID]
				);
				continue;
			}

			if ($this->repoint(slug: $slug) === true) {
				$moved++;
			}
		}

		$output->info(
			sprintf(
				'MigrateSchemaApplicationId: %d schema(s) moved to %s, %d refused as duplicates.',
				$moved,
				self::NEW_APP_ID,
				$refused
			)
		);
	}//end run()

	/**
	 * Slugs already claimed under the NEW application id.
	 *
	 * Returns null on a read failure, which the caller treats as "do nothing".
	 * An empty list and a failed read must not look the same: an empty list
	 * says every move is safe, and a failed read says nothing at all.
	 *
	 * @return array<int, string>|null Lower-cased slugs, or null when unreadable.
	 */
	private function slugsUnderNewAppId(): ?array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT slug FROM `*PREFIX*openregister_schemas` WHERE application = ?',
				[self::NEW_APP_ID]
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateSchemaApplicationId: could not read schemas on the new application id; skipping.',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return array_map(
			static fn (array $row): string => strtolower((string)($row['slug'] ?? '')),
			$rows
		);
	}//end slugsUnderNewAppId()

	/**
	 * Slugs still sitting under the OLD application id.
	 *
	 * @return array<int, string>
	 */
	private function slugsUnderOldAppId(): array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT slug FROM `*PREFIX*openregister_schemas` WHERE application = ?',
				[self::OLD_APP_ID]
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateSchemaApplicationId: could not read schemas on the old application id; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return array_values(array_filter(array_map(
			static fn (array $row): string => (string)($row['slug'] ?? ''),
			$rows
		)));
	}//end slugsUnderOldAppId()

	/**
	 * Move one schema onto the new application id.
	 *
	 * Scoped to the (old application, slug) pair so it can never touch a row
	 * belonging to another app that happens to share a slug.
	 *
	 * @param string $slug The schema slug to move.
	 *
	 * @return bool True when the row was updated.
	 */
	private function repoint(string $slug): bool {
		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET application = ? WHERE application = ? AND slug = ?',
				[self::NEW_APP_ID, self::OLD_APP_ID, $slug]
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateSchemaApplicationId: could not move schema.',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end repoint()
}//end class
