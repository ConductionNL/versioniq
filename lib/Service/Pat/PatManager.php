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

use Exception;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCP\Security\ICrypto;

/**
 * Encapsulates PAT plaintext handling so the rest of the app never sees it.
 *
 * Plaintext appears only in three places:
 *   1. The HTTP request body that uploads the token
 *   2. The argument to `ICrypto::encrypt()` during `create()`
 *   3. The `$plaintext` parameter passed to the `useToken()` callback
 *
 * No method on this class returns plaintext, no plaintext is stored on a
 * property, and `useToken()` discards its decrypted variable in `finally{}`
 * before returning.
 *
 * @psalm-api
 */
class PatManager {
	public function __construct(
		private PatMapper $mapper,
		private ICrypto $crypto,
	) {
	}

	/**
	 * Encrypts and persists a new PAT with hint + validated scopes; see "PAT storage" and "Encryption at rest".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function create(
		NewPat $new,
		#[\SensitiveParameter]
		string $plaintextToken,
	): Pat {
		$pat = new Pat();
		$new->describe($pat);
		$pat->setEncryptedToken($this->crypto->encrypt($plaintextToken));
		$pat->setTokenHint(self::buildHint($plaintextToken));
		$pat->setSharedWithAdmins(false);
		$new->recordValidation($pat, $this->nowString());
		$pat->setCreatedAt($this->nowString());

		return $this->mapper->insert($pat);
	}

	/**
	 * Decrypts a PAT only inside the callback, then updates last-used; see "Encryption at rest" and "Authenticated GitHub fetches".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @template T
	 * @param callable(string): T $callback
	 * @return T
	 */
	public function useToken(Pat $pat, callable $callback): mixed {
		$plaintext = $this->crypto->decrypt($pat->getEncryptedToken());
		try {
			$result = $callback($plaintext);
		} finally {
			$plaintext = null;
			unset($plaintext);
		}

		$pat->setLastUsedAt($this->nowString());
		try {
			$this->mapper->update($pat);
		} catch (Exception) {
			// Best-effort — failing to update last-used must not break the install flow.
		}

		return $result;
	}

	/**
	 * Deletes a PAT row; see "PAT management API".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function delete(Pat $pat): Pat {
		return $this->mapper->delete($pat);
	}

	/**
	 * Persists mutations to a PAT (label / share flag); see "PAT management API".
	 *
	 * Clears the expiry-warning ledger whenever `expiresAt` was changed on the
	 * entity before this call, so a renewed token gets fresh warnings; see
	 * "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function update(Pat $pat): Pat {
		$this->resetLedgerIfExpiryChanged($pat);

		return $this->mapper->update($pat);
	}

	/**
	 * Re-probes and refreshes a PAT's stored scopes/expiry; see "PAT validation on upload"
	 * and "PAT expiry warnings" (ledger reset on expiry change).
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function refreshValidation(Pat $pat, ValidationResult $result): Pat {
		$pat->setLastValidatedScopes(json_encode([
			'scopes' => $result->scopes,
			'warnings' => $result->warnings,
			'validatedAt' => $this->nowString(),
		], JSON_THROW_ON_ERROR));
		if ($result->expiresAt !== null) {
			$pat->setExpiresAt($result->expiresAt);
		}
		$this->resetLedgerIfExpiryChanged($pat);

		return $this->mapper->update($pat);
	}

	/**
	 * Resets the expiry-warning ledger when `expiresAt` is among the entity's
	 * updated fields, i.e. it changed since the entity was loaded; see
	 * "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	private function resetLedgerIfExpiryChanged(Pat $pat): void {
		if (array_key_exists('expiresAt', $pat->getUpdatedFields())) {
			$pat->clearWarnedThresholds();
		}
	}

	/**
	 * Builds the redacted token hint (first 4 + last 4 chars); see "PAT storage".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public static function buildHint(string $token): string {
		if (strlen($token) <= 8) {
			return str_repeat('*', max(strlen($token), 4));
		}

		return substr($token, 0, 4) . '...' . substr($token, -4);
	}

	private function nowString(): string {
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	}
}
