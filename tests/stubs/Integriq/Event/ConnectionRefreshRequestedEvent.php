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
 * Integriq ConnectionRefreshRequestedEvent stub (adopt-connection-registry).
 *
 * Mirrors the constructor in hydra change connection-registry, design D6:
 * `(app, ?key)`. The real class ships in integriq; the test bootstraps load
 * this stub only when that class is absent.
 */

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * An app asks integriq to resolve its connections again after a settings save.
 */
final class ConnectionRefreshRequestedEvent extends Event {
	/**
	 * @param string $app The declaring app id.
	 * @param string|null $key One connection key, or null for every connection of the app.
	 */
	public function __construct(
		public readonly string $app,
		public readonly ?string $key = null,
	) {
		parent::__construct();
	}
}
