<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Installer;

use Exception;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IBinaryFinder;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Runs the finalize phase of an UPDATE in a fresh PHP process.
 *
 * The process that swaps the files booted with the previous version of the app
 * registered: its `Application::register()` ran, its service factories are in
 * the container, and `Coordinator::runLazyRegistration()` is a no-op for an app
 * that is already registered. A repair step of the new version that needs a
 * service only the new `register()` provides, or whose constructor the old
 * version built through a factory of its own, then fails, after the migrations
 * already ran (#611). A child process boots Nextcloud with the new files in
 * place, so the new version is the one registered, and runs the same
 * {@see InstallFinalizer::finalizeHere()}.
 *
 * A fresh install has no previous version in the process and stays in-process,
 * as does an update when no PHP CLI binary or `proc_open` is available.
 *
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/specs/migration-safety/spec.md
 * @psalm-api
 */
class ChildProcessFinalizer {
	/** Set in the child's environment; the child finalizes in-process. */
	public const CHILD_ENV = 'VERSIONIQ_FINALIZE_CHILD';

	/** Prefix of the one result line the child writes to stdout. */
	private const RESULT_PREFIX = 'VERSIONIQ_FINALIZE_RESULT ';

	public function __construct(
		private IBinaryFinder $binaryFinder,
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether this finalize should run in a child process: an update of an
	 * app with an installed version, not already inside the child, and a PHP
	 * CLI binary plus proc_open to start one.
	 *
	 * @param array<string, mixed> $info Parsed `appinfo/info.xml` of the new version.
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function applies(array $info): bool {
		$appId = (string)($info['id'] ?? '');
		if ($appId === '' || getenv(self::CHILD_ENV) === '1') {
			return false;
		}
		if ($this->appConfig->getValueString($appId, 'installed_version', '') === '') {
			return false;
		}
		if ($this->phpBinary() === null) {
			$this->logger->warning('versioniq: no PHP CLI binary or proc_open available; finalizing the update of {app} in-process, where repair steps of the new version may fail against the previous version\'s registrations (#611).', ['app' => $appId]);

			return false;
		}

		return true;
	}

	/**
	 * Runs InstallFinalizer::finalizeHere() in a child process and returns the app id.
	 *
	 * @param array<string, mixed> $info Parsed `appinfo/info.xml` of the new version.
	 * @throws Exception When the child could not start or the finalize failed in it.
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function run(string $appPath, array $info, string $enabled, string $sourceId): string {
		$appId = (string)($info['id'] ?? '');
		$php = $this->phpBinary();
		if ($php === null) {
			throw new Exception('No PHP CLI binary to run the finalize phase in a child process.');
		}

		$command = [
			$php,
			'-r',
			'define("OC_CONSOLE", 1); require $argv[1] . "/lib/base.php"; exit(\\' . self::class . '::main(array_slice($argv, 2)));',
			'--',
			\OC::$SERVERROOT,
			$appId,
			$appPath,
			$enabled,
			$sourceId,
		];
		$env = getenv();
		$env[self::CHILD_ENV] = '1';

		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, \OC::$SERVERROOT, $env);
		if (!is_resource($process)) {
			throw new Exception('Could not start the child process for the finalize phase.');
		}
		fclose($pipes[0]);
		$stdout = (string)stream_get_contents($pipes[1]);
		$stderr = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		$result = self::parseResult($stdout);
		if ($exitCode !== 0 || ($result['ok'] ?? false) !== true) {
			$message = is_string($result['message'] ?? null) && $result['message'] !== ''
				? $result['message']
				: 'The finalize phase failed in the child process (exit ' . $exitCode . '): ' . self::tail($stderr !== '' ? $stderr : $stdout);
			$this->logger->error('versioniq: finalize of {app} failed in the child process: {message}', [
				'app' => $appId,
				'message' => $message,
				'exitCode' => $exitCode,
				'class' => $result['class'] ?? null,
			]);
			throw new Exception($message);
		}

		// The child wrote installed_version, enabled, routes and the
		// last-known-good record; this process still holds the values it read.
		$this->appConfig->clearCache(true);
		$this->appManager->clearAppsCache();

		return $appId;
	}

	/**
	 * Entry point of the child process: finalize in-process and write one result line.
	 *
	 * @param list<string> $args appId, appPath, enabled, sourceId.
	 * @return int Exit code: 0 on success, 1 when the finalize threw, 2 on bad arguments.
	 * @spec openspec/specs/version-management/spec.md
	 */
	public static function main(array $args): int {
		[$appId, $appPath, $enabled, $sourceId] = array_pad($args, 4, '');
		if ($appId === '' || $appPath === '' || !is_file($appPath . '/appinfo/info.xml')) {
			echo self::RESULT_PREFIX . json_encode(['ok' => false, 'message' => 'Bad arguments for the finalize child process.']) . "\n";

			return 2;
		}

		try {
			$info = Server::get(IAppManager::class)->getAppInfoByPath($appPath . '/appinfo/info.xml');
			if (!is_array($info) || ($info['id'] ?? null) !== $appId) {
				throw new Exception('appinfo/info.xml of ' . $appId . ' could not be read in the child process.');
			}
			Server::get(InstallFinalizer::class)->finalizeHere($appPath, $info, $enabled, null, $sourceId !== '' ? $sourceId : 'appstore');
		} catch (\Throwable $e) {
			echo self::RESULT_PREFIX . json_encode(['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES) . "\n";

			return 1;
		}

		echo self::RESULT_PREFIX . json_encode(['ok' => true, 'appId' => $appId]) . "\n";

		return 0;
	}

	/**
	 * The PHP CLI binary for the child, or null when none can be started.
	 */
	private function phpBinary(): ?string {
		if (!function_exists('proc_open')) {
			return null;
		}
		if (PHP_SAPI === 'cli' && PHP_BINARY !== '' && is_executable(PHP_BINARY)) {
			return PHP_BINARY;
		}
		$found = $this->binaryFinder->findBinaryPath('php');

		return is_string($found) && $found !== '' ? $found : null;
	}

	/**
	 * The child's result line, decoded; an empty array when there is none.
	 *
	 * @return array<string, mixed>
	 */
	private static function parseResult(string $stdout): array {
		$lines = array_reverse(explode("\n", $stdout));
		foreach ($lines as $line) {
			if (str_starts_with($line, self::RESULT_PREFIX)) {
				$decoded = json_decode(substr($line, strlen(self::RESULT_PREFIX)), true);

				return is_array($decoded) ? $decoded : [];
			}
		}

		return [];
	}

	private static function tail(string $text): string {
		$text = trim($text);

		return strlen($text) > 500 ? '…' . substr($text, -500) : $text;
	}
}
