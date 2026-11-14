<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Advisory;

use OCP\ServerVersion;

/**
 * Supplies the running server's version string and update channel.
 *
 * This exists only to make the server's advisory row testable. `OCP\ServerVersion`
 * is declared `readonly`, so PHPUnit cannot double it, and its constructor
 * `require`s the server's own `version.php` — which is absent from a unit-test
 * autoload tree. Depending on it directly would mean the server correlation
 * path, the one covering 95 of the 277 published advisories, could not be
 * tested at all.
 *
 * @psalm-api
 */
class ServerVersionProvider {
	public function __construct(
		private ServerVersion $serverVersion,
	) {
	}

	/**
	 * The running server version, e.g. `31.0.2`.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function current(): string {
		return $this->serverVersion->getVersionString();
	}

	/**
	 * The server's update channel, e.g. `stable`; the `server` release channel follows it.
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public function channel(): string {
		return $this->serverVersion->getChannel();
	}
}
