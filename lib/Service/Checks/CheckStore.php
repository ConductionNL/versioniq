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

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Installer\AppIdFormat;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Where the update checks live.
 *
 * Verdict checks are set by an admin and stored as JSON in app config key
 * `update_checks`, at most {@see self::MAX_VERDICT_CHECKS}, each bearer token
 * encrypted with ICrypto and never handed back by {@see self::read()}.
 *
 * Command checks are set by the server operator in `config.php` under
 * `versioniq.update_checks` and only read here: a web form that could set a
 * program to run would let anyone with an admin session run programs on the
 * server. A malformed system value is ignored with a log line, never fatal.
 *
 * @psalm-api
 * @psalm-type VerdictInput = array{id?: mixed, name?: mixed, url?: mixed, apps?: mixed, token?: mixed, clearToken?: mixed}
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
class CheckStore {
	/** App config key holding the verdict checks as JSON. */
	public const KEY = 'update_checks';

	/** System config (`config.php`) key holding the operator's command checks. */
	public const SYSTEM_KEY = 'versioniq.update_checks';

	public const MAX_VERDICT_CHECKS = 10;

	private const MAX_NAME_LENGTH = 100;
	private const MAX_URL_LENGTH = 2000;
	private const MAX_TOKEN_LENGTH = 4096;

	/** The placeholders a verdict address may carry. */
	private const URL_PLACEHOLDERS = ['{appId}', '{version}', '{fromVersion}'];

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What the page shows: the verdict checks without their tokens, and the
	 * operator's command checks, read-only.
	 *
	 * @return array{verdict: list<array<string, mixed>>, command: list<array<string, mixed>>, maxVerdict: int, commandConfigKey: string}
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function read(): array {
		return [
			'verdict' => array_map(static fn (UpdateCheck $check): array => $check->toPublicArray(), $this->verdictChecks()),
			'command' => array_map(static fn (UpdateCheck $check): array => $check->toPublicArray(), $this->commandChecks()),
			'maxVerdict' => self::MAX_VERDICT_CHECKS,
			'commandConfigKey' => self::SYSTEM_KEY,
		];
	}

	/**
	 * Every check, verdict checks first, in the order they were set.
	 *
	 * @return list<UpdateCheck>
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function all(): array {
		return array_merge($this->verdictChecks(), $this->commandChecks());
	}

	/**
	 * The stored verdict checks; a malformed value or entry is skipped with a log line.
	 *
	 * @return list<UpdateCheck>
	 */
	public function verdictChecks(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || !array_is_list($decoded)) {
			$this->logger->warning('Versioniq: the stored verdict checks (app config "update_checks") are not a JSON list and are ignored.');

			return [];
		}

		$checks = [];
		foreach ($decoded as $entry) {
			$check = is_array($entry) ? self::storedVerdict($entry) : null;
			if ($check === null) {
				$this->logger->warning('Versioniq: a stored verdict check without a name or address is ignored.');
				continue;
			}
			$checks[] = $check;
		}

		return array_slice($checks, 0, self::MAX_VERDICT_CHECKS);
	}

	/**
	 * The operator's command checks from `config.php`; a malformed value or
	 * entry is skipped with a log line.
	 *
	 * @return list<UpdateCheck>
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function commandChecks(): array {
		/** @var mixed $value */
		$value = $this->config->getSystemValue(self::SYSTEM_KEY, []);
		if ($value === [] || $value === null || $value === '') {
			return [];
		}
		if (!is_array($value) || !array_is_list($value)) {
			$this->logger->warning('Versioniq: config.php "versioniq.update_checks" is not a list of checks and is ignored.');

			return [];
		}

		$checks = [];
		foreach ($value as $index => $entry) {
			$check = is_array($entry) ? $this->configuredCommand($entry) : null;
			if ($check === null) {
				$this->logger->warning('Versioniq: command check {index} in config.php "versioniq.update_checks" needs a name, a command list of strings, and optionally apps and a timeout in seconds; it is ignored.', ['index' => $index]);
				continue;
			}
			$checks[] = $check;
		}

		return $checks;
	}

	/**
	 * Replaces the verdict checks with `$input`, validated in full before
	 * anything is written. An entry that names the id of a stored check keeps
	 * that check's token unless it sends a new `token` or `clearToken`.
	 *
	 * @param mixed $input A list of {id?, name, url, apps?, token?, clearToken?}.
	 * @return list<array<string, mixed>> The stored checks, without tokens.
	 * @throws InvalidArgumentException when the list or an entry is not acceptable; nothing is written then
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function saveVerdictChecks(mixed $input): array {
		if (!is_array($input) || !array_is_list($input)) {
			throw new InvalidArgumentException('The checks must be a list.');
		}
		if (count($input) > self::MAX_VERDICT_CHECKS) {
			throw new InvalidArgumentException(sprintf('At most %d verdict checks can be set.', self::MAX_VERDICT_CHECKS));
		}

		$existing = [];
		foreach ($this->verdictChecks() as $check) {
			$existing[$check->id] = $check;
		}

		$checks = [];
		foreach ($input as $position => $entry) {
			if (!is_array($entry)) {
				throw new InvalidArgumentException(sprintf('Check %d is not an object.', $position + 1));
			}
			$checks[] = $this->validatedVerdict($entry, $existing, $position + 1);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::KEY, json_encode(array_map(
			static fn (UpdateCheck $check): array => ['id' => $check->id, 'name' => $check->name, 'url' => $check->url, 'apps' => $check->apps, 'token' => $check->encryptedToken],
			$checks,
		), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

		return array_map(static fn (UpdateCheck $check): array => $check->toPublicArray(), $checks);
	}

	/**
	 * @param array<array-key, mixed> $entry
	 * @param array<string, UpdateCheck> $existing
	 */
	private function validatedVerdict(array $entry, array $existing, int $position): UpdateCheck {
		$name = self::requiredText($entry['name'] ?? null, self::MAX_NAME_LENGTH, sprintf('Check %d needs a name of at most %d characters.', $position, self::MAX_NAME_LENGTH));
		$url = self::requiredText($entry['url'] ?? null, self::MAX_URL_LENGTH, sprintf('Check %d needs an address.', $position));
		if (!self::isHttpsTemplate($url)) {
			throw new InvalidArgumentException(sprintf('The address of check "%s" must be an https address.', $name));
		}
		$apps = self::appList($entry['apps'] ?? []);
		if ($apps === null) {
			throw new InvalidArgumentException(sprintf('The apps of check "%s" must be app ids.', $name));
		}

		$id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
		$previous = $existing[$id] ?? null;
		if ($previous === null) {
			$id = bin2hex(random_bytes(6));
		}

		return UpdateCheck::verdict($id, $name, $url, $apps, $this->tokenFor($entry, $previous, $name));
	}

	/**
	 * The encrypted token to store: a new one when sent, none when cleared,
	 * otherwise the stored check's.
	 *
	 * @param array<array-key, mixed> $entry
	 */
	private function tokenFor(array $entry, ?UpdateCheck $previous, string $name): ?string {
		/** @var mixed $token */
		$token = $entry['token'] ?? null;
		if (is_string($token) && trim($token) !== '') {
			$token = trim($token);
			if (strlen($token) > self::MAX_TOKEN_LENGTH || preg_match('/[\s\x00-\x1f\x7f]/', $token) === 1) {
				throw new InvalidArgumentException(sprintf('The token of check "%s" must be one line without spaces.', $name));
			}

			return $this->crypto->encrypt($token);
		}
		if (($entry['clearToken'] ?? false) === true || $previous === null) {
			return null;
		}

		return $previous->encryptedToken;
	}

	/**
	 * An address template is https with a host once its placeholders are filled in.
	 */
	private static function isHttpsTemplate(string $url): bool {
		$filled = str_replace(self::URL_PLACEHOLDERS, 'x', $url);
		$parts = parse_url($filled);

		return is_array($parts)
			&& strtolower($parts['scheme'] ?? '') === 'https'
			&& ($parts['host'] ?? '') !== ''
			&& !isset($parts['user'])
			&& !isset($parts['pass'])
			&& preg_match('/[\s\x00-\x1f\x7f]/', $filled) !== 1;
	}

	/**
	 * A stored verdict entry, read leniently: the address was validated when it was written.
	 *
	 * @param array<array-key, mixed> $entry
	 */
	private static function storedVerdict(array $entry): ?UpdateCheck {
		$name = $entry['name'] ?? null;
		$url = $entry['url'] ?? null;
		if (!is_string($name) || trim($name) === '' || !is_string($url) || trim($url) === '') {
			return null;
		}
		$id = is_string($entry['id'] ?? null) && $entry['id'] !== '' ? $entry['id'] : substr(hash('sha256', $name . "\n" . $url), 0, 12);
		$token = is_string($entry['token'] ?? null) && $entry['token'] !== '' ? $entry['token'] : null;

		return UpdateCheck::verdict($id, trim($name), trim($url), self::appList($entry['apps'] ?? []) ?? [], $token);
	}

	/**
	 * @param array<array-key, mixed> $entry
	 */
	private function configuredCommand(array $entry): ?UpdateCheck {
		$name = $entry['name'] ?? null;
		$command = $entry['command'] ?? null;
		if (!is_string($name) || trim($name) === '' || !is_array($command) || !array_is_list($command) || $command === []) {
			return null;
		}
		foreach ($command as $argument) {
			if (!is_string($argument)) {
				return null;
			}
		}
		if (trim($command[0]) === '') {
			return null;
		}
		$apps = self::appList($entry['apps'] ?? []);
		$timeout = $this->commandTimeout($entry['timeout'] ?? null, $name);
		if ($apps === null || $timeout === null) {
			return null;
		}

		/** @var list<string> $command */
		return UpdateCheck::command(trim($name), $command, $apps, $timeout);
	}

	/**
	 * The timeout in seconds: the default when none is set, at most
	 * {@see UpdateCheck::MAX_COMMAND_TIMEOUT}, null when it is not a positive whole number.
	 */
	private function commandTimeout(mixed $timeout, string $name): ?int {
		if ($timeout === null) {
			return UpdateCheck::DEFAULT_COMMAND_TIMEOUT;
		}
		if (!is_int($timeout) || $timeout < 1) {
			return null;
		}
		if ($timeout > UpdateCheck::MAX_COMMAND_TIMEOUT) {
			$this->logger->warning('Versioniq: command check "{name}" asks for a timeout of {timeout} s; it gets the maximum of {max} s.', ['name' => $name, 'timeout' => $timeout, 'max' => UpdateCheck::MAX_COMMAND_TIMEOUT]);

			return UpdateCheck::MAX_COMMAND_TIMEOUT;
		}

		return $timeout;
	}

	/**
	 * A list of app ids (a comma-separated text is accepted too), without
	 * duplicates; null when an entry is not an app id.
	 *
	 * @return list<string>|null
	 */
	private static function appList(mixed $apps): ?array {
		if (is_string($apps)) {
			$apps = preg_split('/[\s,]+/', $apps, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		}
		if (!is_array($apps)) {
			return null;
		}
		$list = [];
		foreach ($apps as $appId) {
			if (!is_string($appId) || !AppIdFormat::isValid(trim($appId))) {
				return null;
			}
			$list[trim($appId)] = true;
		}

		return array_keys($list);
	}

	private static function requiredText(mixed $value, int $maxLength, string $message): string {
		if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $maxLength) {
			throw new InvalidArgumentException($message);
		}

		return trim($value);
	}
}
