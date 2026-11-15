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
use OCP\IBinaryFinder;
use OCP\ITempManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Runs the finalize phase of an UPDATE in a fresh PHP process.
 *
 * The process that swaps the files booted with the previous version of the app
 * registered: it loaded that version's `Application` class, its `register()`
 * ran and its service factories are in the container. Because this process
 * loaded that class, `Coordinator::runLazyRegistration()` registers the app
 * again with it, as PHP cannot reload a class: the previous `register()` runs
 * again and the new one never runs in this process. A repair step of the new
 * version that needs a service only the new `register()` provides, or whose
 * constructor the old version built through a factory of its own, then fails,
 * after the migrations already ran (#611). A child process boots Nextcloud with the new
 * files in place, so the new version is the one registered, and runs the same
 * {@see InstallFinalizer::finalize()}, which stays in-process there.
 *
 * The child is used for an update only; a fresh install has no previous version
 * in the process and stays in-process. Before the real run a probe starts the
 * child once: it boots Nextcloud and asks its container for InstallFinalizer,
 * and finalizes nothing. That shows the child's PHP can boot Nextcloud and load
 * this app's classes; on PHP 8.4 and later the container hands out lazy
 * objects, so the probe does not run InstallFinalizer's constructor. When no
 * PHP CLI binary, `proc_open` or temporary file is available,
 * or the probe fails (a CLI PHP of another version or without an extension
 * Nextcloud needs, an environment it cannot boot in), nothing ran yet, so run()
 * logs a warning and returns null and the caller finalizes in-process. Once the
 * probe succeeded, a failed real run is a failed finalize, possibly halfway
 * through a migration: run() throws and the update fails, it is not retried
 * in-process. An update therefore boots Nextcloud twice in a child process,
 * once for the probe and once for the finalize.
 *
 * Nextcloud keeps the system config in memory and writes all of it back to
 * `config/config.php` on every change, so a value the child writes (a migration
 * or repair step calling `IConfig::setSystemValue()`) would be reverted by this
 * process's next write. The keys the child added, changed or removed in
 * `config.php` are therefore applied to this process's config after the last
 * child ends, whether it succeeded or failed, and also when only the probe ran.
 * A snapshot without a `version` key counts as unreadable: Nextcloud's own
 * `Config::writeData()` requires that key, so an emptied or cut-off file is not
 * read as "every key removed". Only `config.php` itself is compared, not the
 * `*.config.php` files beside it; Nextcloud writes their merged values into
 * `config.php`, so a key that only comes from a `*.config.php` file can be
 * listed once in the info log, with the value this process already holds. A
 * value this process changes while the child runs would be overwritten, which
 * does not happen: it waits for the child and writes no system config in that
 * time.
 *
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/specs/migration-safety/spec.md
 * @psalm-api
 */
class ChildProcessFinalizer {
	/** Set in the child's environment; the child finalizes in-process. */
	public const CHILD_ENV = 'VERSIONIQ_FINALIZE_CHILD';

	/** The child argument that makes main() a probe: boot, resolve, finalize nothing. */
	public const PROBE_ARG = '--probe';

	/** Prefix of the one result line the child writes to stdout. */
	private const RESULT_PREFIX = 'VERSIONIQ_FINALIZE_RESULT ';

	public function __construct(
		private IBinaryFinder $binaryFinder,
		private ServerAccess $server,
		private LoggerInterface $logger,
		private ITempManager $tempManager,
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
		if ($this->server->configs->appConfig->getValueString($appId, 'installed_version', '') === '') {
			return false;
		}
		if ($this->phpBinary() === null) {
			$this->logger->warning('versioniq: no PHP CLI binary or proc_open available; finalizing the update of {app} in-process, where repair steps of the new version may fail against the previous version\'s registrations (#611).', ['app' => $appId]);

			return false;
		}

		return true;
	}

	/**
	 * Runs InstallFinalizer::finalize() in a child process and returns the app id,
	 * or null when no child could be started or the probe child could not boot
	 * Nextcloud — nothing ran then, so the caller finalizes in-process instead of
	 * failing the install.
	 *
	 * @param array<string, mixed> $info Parsed `appinfo/info.xml` of the new version.
	 * @throws Exception When the finalize failed in the child.
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function run(string $appPath, array $info, string $enabled, string $sourceId): ?string {
		$appId = (string)($info['id'] ?? '');
		$php = $this->phpBinary();
		if ($php === null) {
			$this->logger->warning('versioniq: no PHP CLI binary for the finalize child of {app}; finalizing in-process (#611).', ['app' => $appId]);

			return null;
		}

		$configBefore = self::readConfigFile();
		$probe = $this->startChild($appId, $php, [self::PROBE_ARG]);
		$outcome = $this->probeSucceeded($appId, $probe)
			? $this->startChild($appId, $php, [$appId, $appPath, $enabled, $sourceId])
			: null;
		if ($probe !== null) {
			// After whichever child ran last, also when only the probe ran.
			$this->syncSystemConfig($appId, $configBefore, self::readConfigFile());
		}
		if ($outcome === null) {
			return null;
		}
		$this->failOnChildFailure($appId, $outcome);

		// The child wrote installed_version, enabled, routes and the
		// last-known-good record; this process still holds the values it read.
		$this->server->configs->appConfig->clearCache(true);
		$this->server->appManager->clearAppsCache();

		return $appId;
	}

	/**
	 * Entry point of the child process: finalize in-process and write one result
	 * line, or, with only {@see self::PROBE_ARG}, check that Nextcloud booted.
	 *
	 * @param list<string> $args appId, appPath, enabled, sourceId; or the probe argument.
	 * @return int Exit code: 0 on success, 1 when the finalize threw, 2 on bad arguments.
	 * @spec openspec/specs/version-management/spec.md
	 */
	public static function main(array $args): int {
		// Whoever started this process, InstallFinalizer::finalize() must not
		// hand the finalize to yet another child.
		putenv(self::CHILD_ENV . '=1');
		if ($args === [self::PROBE_ARG]) {
			return self::probe();
		}
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
			Server::get(InstallFinalizer::class)->finalize($appPath, $info, $enabled, null, $sourceId !== '' ? $sourceId : 'appstore');
		} catch (\Throwable $e) {
			echo self::RESULT_PREFIX . json_encode(['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES) . "\n";

			return 1;
		}

		echo self::RESULT_PREFIX . json_encode(['ok' => true, 'appId' => $appId]) . "\n";

		return 0;
	}

	/**
	 * The probe in the child: Nextcloud booted, and its container hands out
	 * InstallFinalizer (on PHP 8.4 and later a lazy object whose constructor
	 * has not run). Finalizes nothing.
	 *
	 * @return int Exit code: 0 when the container returned it, 1 when it threw.
	 * @spec openspec/specs/version-management/spec.md
	 */
	private static function probe(): int {
		try {
			Server::get(InstallFinalizer::class);
		} catch (\Throwable $e) {
			echo self::RESULT_PREFIX . json_encode(['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES) . "\n";

			return 1;
		}

		echo self::RESULT_PREFIX . json_encode(['ok' => true, 'probe' => true]) . "\n";

		return 0;
	}

	/**
	 * The command that starts the child: PHP boots Nextcloud and calls main()
	 * with the arguments.
	 *
	 * @param list<string> $childArgs appId, appPath, enabled, sourceId; or the probe argument.
	 * @return list<string>
	 * @spec openspec/specs/version-management/spec.md
	 */
	protected function childCommand(string $php, array $childArgs): array {
		return [
			$php,
			'-r',
			'define("OC_CONSOLE", 1); require $argv[1] . "/lib/base.php"; exit(\\' . self::class . '::main(array_slice($argv, 2)));',
			'--',
			\OC::$SERVERROOT,
			...$childArgs,
		];
	}

	/**
	 * Starts one child with a stderr file of its own and waits for it; null,
	 * with a warning, when no temporary file was available or it could not be
	 * started.
	 *
	 * @param list<string> $childArgs
	 * @return ?array{exitCode: int, stdout: string, stderr: string}
	 * @spec openspec/specs/version-management/spec.md
	 */
	private function startChild(string $appId, string $php, array $childArgs): ?array {
		// stderr goes to a file, not a pipe: reading two pipes one after the
		// other blocks the child once it fills the unread one (~64 KB).
		// ITempManager, not sys_get_temp_dir(): it honours `tempdirectory` and
		// stays inside an open_basedir that excludes the system temp dir.
		$stderrFile = $this->tempManager->getTemporaryFile('.log');
		if (!is_string($stderrFile) || $stderrFile === '') {
			$this->logger->warning('versioniq: no temporary file for the finalize child of {app}; finalizing in-process (#611).', ['app' => $appId]);

			return null;
		}

		$outcome = self::runChild($this->childCommand($php, $childArgs), $stderrFile);
		if ($outcome === null) {
			$this->logger->warning('versioniq: could not start the finalize child of {app}; finalizing in-process (#611).', ['app' => $appId]);
		}

		return $outcome;
	}

	/**
	 * Whether the probe child booted Nextcloud and got InstallFinalizer from
	 * its container.
	 * Logs one warning when it ran and did not; a probe that could not be
	 * started was already logged.
	 *
	 * @param ?array{exitCode: int, stdout: string, stderr: string} $probe
	 * @spec openspec/specs/version-management/spec.md
	 */
	private function probeSucceeded(string $appId, ?array $probe): bool {
		if ($probe === null) {
			return false;
		}
		if ($probe['exitCode'] === 0 && self::parseResult($probe['stdout'])['ok']) {
			return true;
		}

		$this->logger->warning('versioniq: the finalize child of {app} could not boot Nextcloud (exit {exitCode}): {output}; nothing ran, finalizing in-process (#611).', [
			'app' => $appId,
			'exitCode' => $probe['exitCode'],
			'output' => self::probeFailureOutput($probe),
		]);

		return false;
	}

	/**
	 * What a failed probe said: its result line's message (with the class),
	 * else the tail of its stderr, else the tail of its stdout.
	 *
	 * @param array{exitCode: int, stdout: string, stderr: string} $probe
	 */
	private static function probeFailureOutput(array $probe): string {
		$result = self::parseResult($probe['stdout']);
		if ($result['message'] !== '') {
			return ($result['class'] !== null ? $result['class'] . ': ' : '') . $result['message'];
		}

		return self::tail($probe['stderr'] !== '' ? $probe['stderr'] : $probe['stdout']);
	}

	/**
	 * Starts the child and waits for it; null when it could not be started.
	 *
	 * @param list<string> $command
	 * @return ?array{exitCode: int, stdout: string, stderr: string}
	 */
	private static function runChild(array $command, string $stderrFile): ?array {
		$env = getenv();
		$env[self::CHILD_ENV] = '1';
		// @: a failed start returns false, the designed outcome, and PHP would
		// also raise a warning that Nextcloud logs next to versioniq's own.
		$process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']], $pipes, \OC::$SERVERROOT, $env);
		if (!is_resource($process)) {
			@unlink($stderrFile);

			return null;
		}
		fclose($pipes[0]);
		$stdout = (string)stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$exitCode = proc_close($process);
		$stderr = (string)@file_get_contents($stderrFile);
		@unlink($stderrFile);

		return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
	}

	/**
	 * Throws when the child exited non-zero or did not report success.
	 *
	 * @param array{exitCode: int, stdout: string, stderr: string} $outcome
	 * @throws Exception
	 */
	private function failOnChildFailure(string $appId, array $outcome): void {
		$result = self::parseResult($outcome['stdout']);
		if ($outcome['exitCode'] === 0 && $result['ok']) {
			return;
		}

		$message = $result['message'] !== ''
			? $result['message']
			: 'The finalize phase failed in the child process (exit ' . $outcome['exitCode'] . '): ' . self::tail($outcome['stderr'] !== '' ? $outcome['stderr'] : $outcome['stdout']);
		$this->logger->error('versioniq: finalize of {app} failed in the child process: {message}', [
			'app' => $appId,
			'message' => $message,
			'exitCode' => $outcome['exitCode'],
			'class' => $result['class'],
		]);
		throw new Exception($message);
	}

	/**
	 * Applies the keys the child added, changed or removed in config.php to
	 * this process's system config, in one write. Logs the keys, never the
	 * values: config.php holds secrets.
	 *
	 * @param ?array<array-key, mixed> $before config.php before the child started.
	 * @param ?array<array-key, mixed> $after config.php after the child ended.
	 */
	private function syncSystemConfig(string $appId, ?array $before, ?array $after): void {
		// Config::writeData() requires `version`; a snapshot without it is an
		// emptied or cut-off file, not "the child removed every key".
		if ($before === null || $after === null || !isset($before['version'], $after['version'])) {
			$this->logger->warning('versioniq: could not read config/config.php, or it has no `version` key, around the finalize child of {app}; a system config value the child wrote may be reverted by this process\'s next config write (#611).', ['app' => $appId]);

			return;
		}

		$changes = self::configChanges($before, $after);
		if ($changes === []) {
			return;
		}

		$keys = implode(', ', array_map(static fn (int|string $key): string => (string)$key, array_keys($changes)));
		$this->logger->info('versioniq: the finalize child of {app} changed the system config keys {keys}; applying them to this process so its next config write keeps them (#611).', ['app' => $appId, 'keys' => $keys]);
		try {
			$this->server->configs->config->setSystemValues($changes);
		} catch (\Throwable $e) {
			$this->logger->warning('versioniq: could not apply the system config keys {keys} the finalize child of {app} changed: {message}', ['app' => $appId, 'keys' => $keys, 'message' => $e->getMessage()]);
		}
	}

	/**
	 * The keys added or changed (with their new value) and removed (with null)
	 * between two reads of config.php; values are compared strictly.
	 *
	 * @param array<array-key, mixed> $before
	 * @param array<array-key, mixed> $after
	 * @return array<array-key, mixed>
	 */
	private static function configChanges(array $before, array $after): array {
		$changes = [];
		/** @psalm-suppress MixedAssignment A config value can be anything config.php holds. */
		foreach ($after as $key => $value) {
			if (!array_key_exists($key, $before) || $before[$key] !== $value) {
				$changes[$key] = $value;
			}
		}
		foreach (array_keys($before) as $key) {
			if (!array_key_exists($key, $after)) {
				$changes[$key] = null;
			}
		}

		return $changes;
	}

	/**
	 * config.php, read the way Nextcloud reads it (fresh stat and opcache, a
	 * shared lock); null when it cannot be read.
	 *
	 * @return ?array<array-key, mixed>
	 */
	private static function readConfigFile(): ?array {
		$configDir = \OC::$configDir;
		if (!is_string($configDir) || $configDir === '') {
			return null;
		}
		$file = $configDir . 'config.php';
		clearstatcache(true, $file);
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($file, false);
		}
		$handle = is_file($file) ? @fopen($file, 'r') : false;
		if ($handle === false) {
			return null;
		}

		try {
			return flock($handle, LOCK_SH) ? self::includeConfig($file) : null;
		} catch (\Throwable) {
			return null;
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * Includes a config file in a scope of its own and returns its `$CONFIG`.
	 *
	 * @return ?array<array-key, mixed>
	 */
	private static function includeConfig(string $file): ?array {
		/** @var mixed $CONFIG The include below may replace it with anything. */
		$CONFIG = [];
		/** @psalm-suppress UnresolvableInclude The server's own config file, at a path only known at run time. */
		include $file;

		return is_array($CONFIG) ? $CONFIG : null;
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
	 * The child's result line, decoded; not ok and without a message when
	 * there is none.
	 *
	 * @return array{ok: bool, message: string, class: ?string}
	 */
	private static function parseResult(string $stdout): array {
		foreach (array_reverse(explode("\n", $stdout)) as $line) {
			if (!str_starts_with($line, self::RESULT_PREFIX)) {
				continue;
			}
			$decoded = (array)json_decode(substr($line, strlen(self::RESULT_PREFIX)), true);

			return [
				'ok' => ($decoded['ok'] ?? null) === true,
				'message' => isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : '',
				'class' => isset($decoded['class']) && is_string($decoded['class']) ? $decoded['class'] : null,
			];
		}

		return ['ok' => false, 'message' => '', 'class' => null];
	}

	private static function tail(string $text): string {
		$text = trim($text);

		return strlen($text) > 500 ? '…' . substr($text, -500) : $text;
	}
}
