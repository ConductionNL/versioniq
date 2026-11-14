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
 * Runs one command check the server operator set in `config.php`: the program
 * and its arguments go to proc_open() as an array, so no shell ever parses
 * them, with `{appId}`, `{version}`, `{fromVersion}` and `{path}` substituted
 * inside each argument as plain text. It passes only on exit code 0 within
 * its timeout; a program still running then is killed.
 *
 * Only the last {@see self::OUTPUT_TAIL_BYTES} bytes of its output (stdout and
 * stderr together) are kept, with anything that looks like a bearer token or
 * an Authorization header redacted, as the audit logger does.
 *
 * @psalm-api
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
class CommandCheck {
	/** The output kept for the report: the last 2 KiB. */
	public const OUTPUT_TAIL_BYTES = 2048;

	/** How long one wait for output lasts, in microseconds. */
	private const POLL_MICROSECONDS = 100_000;

	/**
	 * @return array{passed: bool, detail: string, exitCode: ?int}
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function run(UpdateCheck $check, CheckSubject $subject): array {
		$argv = self::argumentsFor($check, $subject);
		$pipes = [];
		$process = @proc_open(
			$argv,
			[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			is_dir($subject->path) ? $subject->path : null,
		);
		if (!is_resource($process)) {
			return ['passed' => false, 'detail' => 'The program could not be started.', 'exitCode' => null];
		}

		[$exitCode, $output, $timedOut] = $this->collect($process, $pipes, $check->timeout);
		$tail = self::tail($output);
		if ($timedOut) {
			return ['passed' => false, 'detail' => self::withOutput(sprintf('Timed out after %d s.', $check->timeout), $tail), 'exitCode' => null];
		}

		return ['passed' => $exitCode === 0, 'detail' => self::withOutput(sprintf('Exit code %d.', $exitCode ?? -1), $tail), 'exitCode' => $exitCode];
	}

	/**
	 * The program and its arguments with the placeholders filled in, each
	 * argument kept whole: a `;` or a space stays text inside its argument.
	 *
	 * @return list<string>
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public static function argumentsFor(UpdateCheck $check, CheckSubject $subject): array {
		$values = $subject->placeholders(static fn (string $value): string => $value, true);

		return array_map(static fn (string $argument): string => strtr($argument, $values), $check->command);
	}

	/**
	 * Reads the output until the program exits or the timeout passes, keeping
	 * only the tail, then closes the process.
	 *
	 * @param resource $process
	 * @param array<int, resource> $pipes
	 * @return array{0: ?int, 1: string, 2: bool} exit code, output tail, timed out
	 */
	private function collect($process, array $pipes, int $timeout): array {
		$streams = [$pipes[1], $pipes[2]];
		foreach ($streams as $stream) {
			stream_set_blocking($stream, false);
		}
		$deadline = microtime(true) + $timeout;
		$output = '';
		$exitCode = null;
		$timedOut = false;

		while (true) {
			$output = self::keepTail($output . $this->readAvailable($streams));
			$status = proc_get_status($process);
			if (!$status['running']) {
				$exitCode = $status['exitcode'];
				break;
			}
			if (microtime(true) >= $deadline) {
				$timedOut = true;
				proc_terminate($process, 9);
				break;
			}
		}
		// Whatever the program wrote just before it exited.
		$output = self::keepTail($output . $this->drain($streams));
		foreach ($streams as $stream) {
			fclose($stream);
		}
		proc_close($process);

		return [$exitCode, $output, $timedOut];
	}

	/**
	 * What the streams hold now, waiting at most one poll interval for it.
	 *
	 * @param list<resource> $streams
	 */
	private function readAvailable(array $streams): string {
		$read = array_values(array_filter($streams, static fn ($stream): bool => !feof($stream)));
		if ($read === []) {
			usleep(self::POLL_MICROSECONDS);

			return '';
		}
		$write = null;
		$except = null;
		if (@stream_select($read, $write, $except, 0, self::POLL_MICROSECONDS) === false) {
			return '';
		}
		$chunk = '';
		foreach ($read as $stream) {
			$chunk .= (string)fread($stream, 65536);
		}

		return $chunk;
	}

	/**
	 * @param list<resource> $streams
	 */
	private function drain(array $streams): string {
		$chunk = '';
		foreach ($streams as $stream) {
			$chunk = self::keepTail($chunk . (string)stream_get_contents($stream));
		}

		return $chunk;
	}

	private static function keepTail(string $output): string {
		return strlen($output) > self::OUTPUT_TAIL_BYTES ? substr($output, -self::OUTPUT_TAIL_BYTES) : $output;
	}

	/**
	 * The last 2 KiB, valid UTF-8 and with tokens redacted.
	 */
	private static function tail(string $output): string {
		$output = mb_scrub(self::keepTail($output), 'UTF-8');
		$output = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $output) ?? $output;
		$output = preg_replace('/Authorization:\s*\S+/i', 'Authorization: [redacted]', $output) ?? $output;

		return trim(self::keepTail($output));
	}

	private static function withOutput(string $summary, string $tail): string {
		return $tail === '' ? $summary : $summary . "\n" . $tail;
	}
}
