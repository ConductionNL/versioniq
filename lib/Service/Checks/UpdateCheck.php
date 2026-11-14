<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Checks;

/**
 * One check that must pass before an update: a verdict check an admin set on
 * the Settings tab (an https address that must answer 2xx), or a command
 * check the server operator set in `config.php` (a program that must exit 0).
 *
 * A verdict check carries its bearer token only in encrypted form; the
 * plaintext exists only inside {@see HttpVerdictCheck} for the one request.
 *
 * @psalm-api
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
final class UpdateCheck {
	public const KIND_VERDICT = 'verdict';
	public const KIND_COMMAND = 'command';

	/** Seconds a verdict address has to answer. */
	public const VERDICT_TIMEOUT = 15;

	/** Seconds a command check gets when the operator names none. */
	public const DEFAULT_COMMAND_TIMEOUT = 60;

	/** The longest a command check may run, in seconds. */
	public const MAX_COMMAND_TIMEOUT = 1800;

	/**
	 * @param list<string> $apps The app ids the check applies to; empty means every app.
	 * @param list<string> $command The program and its arguments (command checks only).
	 */
	private function __construct(
		public readonly string $kind,
		public readonly string $id,
		public readonly string $name,
		public readonly array $apps,
		public readonly string $url,
		public readonly ?string $encryptedToken,
		public readonly array $command,
		public readonly int $timeout,
	) {
	}

	/**
	 * @param list<string> $apps
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public static function verdict(string $id, string $name, string $url, array $apps, ?string $encryptedToken): self {
		return new self(self::KIND_VERDICT, $id, $name, $apps, $url, $encryptedToken, [], self::VERDICT_TIMEOUT);
	}

	/**
	 * @param list<string> $command
	 * @param list<string> $apps
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public static function command(string $name, array $command, array $apps, int $timeout): self {
		return new self(self::KIND_COMMAND, '', $name, $apps, '', null, $command, $timeout);
	}

	/**
	 * Whether the check applies to the app: it lists the app, or lists none.
	 *
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function appliesTo(string $appId): bool {
		return $this->apps === [] || in_array($appId, $this->apps, true);
	}

	public function hasToken(): bool {
		return $this->encryptedToken !== null && $this->encryptedToken !== '';
	}

	/**
	 * What the page and the API may show: never the token, only whether there is one.
	 *
	 * @return array{id: string, name: string, url: string, apps: list<string>, hasToken: bool}|array{name: string, command: list<string>, apps: list<string>, timeout: int}
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function toPublicArray(): array {
		if ($this->kind === self::KIND_VERDICT) {
			return ['id' => $this->id, 'name' => $this->name, 'url' => $this->url, 'apps' => $this->apps, 'hasToken' => $this->hasToken()];
		}

		return ['name' => $this->name, 'command' => $this->command, 'apps' => $this->apps, 'timeout' => $this->timeout];
	}
}
