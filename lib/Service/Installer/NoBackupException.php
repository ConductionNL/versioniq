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

use RuntimeException;

/**
 * A restore was asked for an app that has no kept backup; the admin rolls
 * back to an earlier version instead (safety-rollback-backup-and-preflight D4).
 *
 * @spec openspec/specs/migration-safety/spec.md#requirement-restoring-a-broken-install-puts-back-files-and-tables
 */
final class NoBackupException extends RuntimeException {
}
