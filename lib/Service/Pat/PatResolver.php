<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Pat;

use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;

/**
 * Looks up the highest-priority non-expired PAT visible to the current uid
 * (or, for a background job with no uid, shared with admins)
 * that matches the binding's `owner/repo`. Used by `GithubReleaseSource` to
 * decide whether to authenticate a request.
 *
 * @psalm-api
 */
class PatResolver {
	public function __construct(
		private PatMapper $mapper,
	) {
	}

	/**
	 * Finds the highest-priority non-expired PAT for the given forge matching owner/repo; see "Authenticated GitHub fetches" ("Expired PAT skipped").
	 *
	 * Only tokens whose `forge` equals the requested forge are considered, so a
	 * Codeberg binding never authenticates with a GitHub token and vice-versa.
	 * Legacy PAT rows default to forge `github`, so they keep serving GitHub.
	 *
	 * With no uid (a background job: advisory refresh, automatic update, pin
	 * reconcile) only tokens an admin shared with all admins are considered.
	 * A private token is never used on behalf of nobody, and without this a
	 * private repository answered every job with 404 and read as clean (#430).
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function findFor(string $forge, string $ownerRepo, ?string $currentUid): ?Pat {
		$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$candidates = array_values(array_filter(
			$this->candidatesFor($currentUid),
			static fn (Pat $pat): bool => $pat->getForge() === $forge,
		));

		// Prefer owner-owned PATs over shared ones; within each tier, prefer most-specific pattern.
		usort($candidates, function (Pat $a, Pat $b) use ($currentUid): int {
			$aOwn = $a->getOwnerUid() === $currentUid;
			$bOwn = $b->getOwnerUid() === $currentUid;
			if ($aOwn !== $bOwn) {
				return $aOwn ? -1 : 1;
			}

			return strlen($b->getTargetPattern()) <=> strlen($a->getTargetPattern());
		});

		foreach ($candidates as $pat) {
			if ($pat->getExpiresAt() !== null && $pat->getExpiresAt() <= $now) {
				continue;
			}
			if (fnmatch($pat->getTargetPattern(), $ownerRepo, FNM_NOESCAPE)) {
				return $pat;
			}
		}

		return null;
	}

	/**
	 * Tokens a lookup may choose from: those visible to the uid, or, with no
	 * uid, the tokens shared with admins.
	 *
	 * @return list<Pat>
	 */
	private function candidatesFor(?string $currentUid): array {
		if ($currentUid !== null) {
			return $this->mapper->findVisibleTo($currentUid);
		}

		return array_values(array_filter(
			$this->mapper->findAll(),
			static fn (Pat $pat): bool => $pat->getSharedWithAdmins(),
		));
	}
}
