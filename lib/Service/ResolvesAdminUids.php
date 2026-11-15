<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service;

/**
 * The uids of the admin group, each once, in group order. The using class
 * holds the group manager as `$this->admin->groupManager`.
 */
trait ResolvesAdminUids {
	/**
	 * @return list<string>
	 */
	private function adminUids(): array {
		$uids = [];
		foreach ($this->admin->groupManager->get('admin')?->getUsers() ?? [] as $user) {
			$uids[] = $user->getUID();
		}

		return array_values(array_unique($uids));
	}
}
