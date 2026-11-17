<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Controller;

/**
 * The explicit administrator guard of the admin-only controllers: true only
 * for a signed-in member of the admin group. A controller using it holds the
 * `$userSession` (IUserSession) and `$groupManager` (IGroupManager) it reads.
 */
trait ChecksAdmin {
	/**
	 * Whether the caller is a signed-in administrator.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
