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
 * Adds `app_versions_audit.origin`: where an audited operation came from
 * (web, cli, job, listener). Nullable, so the rows written before it read as
 * unknown.
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-every-audit-row-says-where-it-came-from
 */
class Version1005Date20261114120000 extends SimpleMigrationStep {
	/**
	 * Add the column when it is missing.
	 *
	 * @param IOutput $output The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array $options The options.
	 * @return ISchemaWrapper|null
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-every-audit-row-says-where-it-came-from
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('app_versions_audit')) {
			return null;
		}

		$table = $schema->getTable('app_versions_audit');
		if ($table->hasColumn('origin')) {
			return null;
		}

		$table->addColumn('origin', Types::STRING, ['notnull' => false, 'length' => 16]);
		return $schema;
	}
}
