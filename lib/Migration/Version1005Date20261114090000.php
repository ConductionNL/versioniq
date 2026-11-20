<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the nullable `advisories` column to the audit table
 * (audit-security-reports D2): a JSON list of `{id, severity}` naming the
 * advisories an entry is about. Old rows read as null; nothing is
 * back-filled. Guarded by hasColumn, so a re-run changes nothing.
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md#requirement-advisory-exposure-and-server-updates-are-recorded-in-the-history
 */
class Version1005Date20261114090000 extends SimpleMigrationStep {
	/**
	 * The audit table; the name stays on the old app id on purpose (see AuditEntryMapper).
	 */
	public const TABLE = 'app_versions_audit';

	/**
	 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md#requirement-advisory-exposure-and-server-updates-are-recorded-in-the-history
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('advisories')) {
			return null;
		}

		$table->addColumn('advisories', Types::TEXT, ['notnull' => false]);

		return $schema;
	}
}
