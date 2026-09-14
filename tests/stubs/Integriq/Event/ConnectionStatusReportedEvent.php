<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/*
 * Integriq ConnectionStatusReportedEvent stub (adopt-connection-registry).
 *
 * Mirrors the constructor in hydra change connection-registry, design D6,
 * verbatim: parameter names, order and defaults. A stub that differs from the
 * contract would encode the caller's bug as correct. The real class ships in
 * integriq; the test bootstraps load this stub only when that class is absent,
 * and psalm reads it so it sees the constructor the report service builds.
 */

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * An app reports what only it can observe about one declared connection.
 */
final class ConnectionStatusReportedEvent extends Event {
	/**
	 * @param string $app The declaring app id.
	 * @param string $key The connection key from the app's connections.json.
	 * @param string $status configured, limited, unconfigured, simulated, unavailable or error.
	 * @param string $message What the app observed.
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $key,
		public readonly string $status,
		public readonly string $message = '',
	) {
		parent::__construct();
	}
}
