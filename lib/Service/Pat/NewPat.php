<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Pat;

use OCA\Versioniq\Db\Pat;

/**
 * Everything about a PAT to store except its token: owner, forge, label,
 * target pattern, kind and the validation it passed. Built step by step,
 * each step returning a copy. The plaintext token is never held here.
 */
final class NewPat {
	private string $forge = 'github';
	private string $label = '';
	private string $targetPattern = '';
	private string $kind = '';

	public function __construct(
		private string $ownerUid,
		private ValidationResult $validation,
	) {
	}

	/**
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function withForge(string $forge): self {
		$new = clone $this;
		$new->forge = $forge;

		return $new;
	}

	/**
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function describedAs(string $label, string $targetPattern): self {
		$new = clone $this;
		$new->label = $label;
		$new->targetPattern = $targetPattern;

		return $new;
	}

	/**
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function ofKind(string $kind): self {
		$new = clone $this;
		$new->kind = $kind;

		return $new;
	}

	/**
	 * Sets owner, label, kind, forge and target pattern on the row.
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function describe(Pat $pat): void {
		$pat->setOwnerUid($this->ownerUid);
		$pat->setLabel($this->label);
		$pat->setKind($this->kind);
		$pat->setForge($this->forge);
		$pat->setTargetPattern($this->targetPattern);
	}

	/**
	 * Sets the validated scopes and warnings, stamped `$validatedAt`, and the expiry on the row.
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function recordValidation(Pat $pat, string $validatedAt): void {
		$pat->setLastValidatedScopes(json_encode([
			'scopes' => $this->validation->scopes,
			'warnings' => $this->validation->warnings,
			'validatedAt' => $validatedAt,
		], JSON_THROW_ON_ERROR));
		$pat->setExpiresAt($this->validation->expiresAt);
	}
}
