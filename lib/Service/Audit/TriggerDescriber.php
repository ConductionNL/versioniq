<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Audit;

use OCP\IRequest;

/**
 * Says what triggered a change made outside Versioniq: the command line on
 * the command line (for `occ`, "occ" and its arguments), the request method
 * and path in a web request, or "unknown".
 *
 * A command line can carry a secret, so the value of every password or token
 * option is replaced, and the text is cut at 200 characters.
 *
 * @psalm-api
 */
class TriggerDescriber {
	public const UNKNOWN = 'unknown';
	public const MAX_LENGTH = 200;

	/** Options whose value is never written down. */
	private const SECRET_OPTIONS = ['--password', '--token', '--pass'];

	public function __construct(
		private IRequest $request,
	) {
	}

	/**
	 * The trigger of the current process.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function describe(): string {
		if (PHP_SAPI === 'cli') {
			/** @var mixed $argv */
			$argv = $_SERVER['argv'] ?? [];

			/** @var list<string> $arguments */
			$arguments = is_array($argv) ? array_values(array_filter($argv, is_string(...))) : [];

			return self::describeCommand($arguments);
		}

		return self::describeRequest($this->request->getMethod(), $this->request->getRequestUri());
	}

	/**
	 * A command line as text: the script's base name (so `occ`, not its path)
	 * and its arguments, secrets redacted, at most 200 characters.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @param list<string> $argv
	 */
	public static function describeCommand(array $argv): string {
		if ($argv === []) {
			return self::UNKNOWN;
		}
		$words = [basename($argv[0])];
		$redactNext = false;
		foreach (array_slice($argv, 1) as $argument) {
			if ($redactNext) {
				$words[] = '[redacted]';
				$redactNext = false;
				continue;
			}
			$option = strtolower(explode('=', $argument, 2)[0]);
			if (in_array($option, self::SECRET_OPTIONS, true)) {
				if (str_contains($argument, '=')) {
					$words[] = explode('=', $argument, 2)[0] . '=[redacted]';
				} else {
					$words[] = $argument;
					$redactNext = true;
				}
				continue;
			}
			$words[] = $argument;
		}

		return mb_substr(implode(' ', $words), 0, self::MAX_LENGTH);
	}

	/**
	 * A web request as its method and path, without the query string.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public static function describeRequest(string $method, string $uri): string {
		$path = explode('?', $uri, 2)[0];
		if ($method === '' || $path === '') {
			return self::UNKNOWN;
		}

		return mb_substr(strtoupper($method) . ' ' . $path, 0, self::MAX_LENGTH);
	}
}
