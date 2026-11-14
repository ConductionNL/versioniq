<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Command;

use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\InstallerService;
use OCP\AppFramework\Http;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:install <appId> <version> [--source=] [--dry-run]
 * [--allow-downgrade] [--force-incompatible] [--json]` — installs a specific version through the
 * same `InstallerService::installAppVersion()` path the HTTP API uses
 * (source resolution, allowlist, integrity verification, backup/restore,
 * maintenance-mode, finalize) — no duplicated install logic.
 *
 * CLI execution runs as the server user with full config access — the trust
 * context is equivalent to `occ app:install`, so no password confirmation
 * applies; see "CLI trust context".
 *
 * @spec openspec/specs/cli-commands/spec.md
 * @spec openspec/changes/install-force-incompatible/specs/cli-commands/spec.md
 * @psalm-api
 */
class InstallVersion extends Command {
	/**
	 * Exit-code map (also documented in the command help and docs/cli.md):
	 * 0 success/dry-run-ok, 1 unknown/unclassified, 2 unknown app / bad
	 * arguments, 3 downgrade refused, 4 preflight_permission, 5 download,
	 * 6 integrity (checksum_mismatch|appid_mismatch|version_mismatch|sha_mismatch),
	 * 7 incompatible, 8 finalize, 9 untrusted source.
	 */
	private const EXIT_OK = 0;
	private const EXIT_UNKNOWN = 1;
	private const EXIT_BAD_ARGUMENTS = 2;
	private const EXIT_DOWNGRADE_REFUSED = 3;
	private const EXIT_PREFLIGHT_PERMISSION = 4;
	private const EXIT_DOWNLOAD = 5;
	private const EXIT_INTEGRITY = 6;
	private const EXIT_INCOMPATIBLE = 7;
	private const EXIT_FINALIZE = 8;
	private const EXIT_UNTRUSTED_SOURCE = 9;

	public function __construct(
		private InstallerService $installerService,
	) {
		parent::__construct();
	}

	/**
	 * Declare the command name, arguments and options.
	 *
	 * The name moved from `app_versions:install` to `versioniq:install` with
	 * the app id — `occ` command prefixes follow the app, so an admin script
	 * on the old name breaks loudly rather than silently.
	 *
	 * @spec openspec/specs/cli-commands/spec.md
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:install')
			->setDescription('Install a specific version of an already-installed app.')
			->addArgument('appId', InputArgument::REQUIRED, 'The app id to install.')
			->addArgument('version', InputArgument::REQUIRED, 'The target version to install.')
			->addOption('source', null, InputOption::VALUE_REQUIRED, 'One-off source id override (e.g. github:owner/repo), instead of the app\'s bound source.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Evaluate the install (integrity checks, downgrade detection) without swapping any files.')
			->addOption('allow-downgrade', null, InputOption::VALUE_NONE, 'Acknowledge and proceed with a downgrade (target version older than installed). Refused without this flag.')
			->addOption('accept-new-sha', null, InputOption::VALUE_NONE, 'Accept a changed SHA-256 for a version whose digest was previously recorded (trust-on-first-use), replacing the recorded digest. Refused without this flag when the digest differs.')
			->addOption('force-incompatible', null, InputOption::VALUE_NONE, 'Install past the version\'s declared maximum Nextcloud version through app_install_overwrite; Versioniq lifts the override at the next major upgrade. A version that needs a newer Nextcloud still fails with exit code 7.')
			->addOption('json', null, InputOption::VALUE_NONE, 'Emit the structured outcome as JSON instead of human-readable text.')
			->setHelp(
				'Exit codes: 0 success/dry-run-ok, 1 unknown/unclassified, 2 unknown app / bad arguments, '
				. '3 downgrade refused (pass --allow-downgrade to proceed), 4 preflight permission, '
				. '5 download failure, 6 integrity failure (checksum/appId/version mismatch), '
				. '7 incompatible version, 8 finalize failure (see installStatus), 9 untrusted source.'
			);
	}

	/**
	 * @spec openspec/specs/cli-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = trim((string)$input->getArgument('appId'));
		$version = trim((string)$input->getArgument('version'));
		$json = (bool)$input->getOption('json');

		// Self/core guard, mirrored from the API and checked here up front so
		// this command can report the refusal without an install attempt;
		// see "CLI trust context". Reuses the shared predicate rather than
		// duplicating the self/core check (Task 1).
		if (!$this->installerService->isManageableApp($appId)) {
			$message = sprintf('"%s" cannot be installed or updated from Versioniq (it is Versioniq itself or a core/always-enabled app).', $appId);
			$this->reportRefusal($output, $json, ['appId' => $appId, 'toVersion' => $version, 'message' => $message]);

			return self::EXIT_BAD_ARGUMENTS;
		}

		/** @var mixed $sourceOption */
		$sourceOption = $input->getOption('source');
		$sourceOverride = is_string($sourceOption) && trim($sourceOption) !== '' ? trim($sourceOption) : null;
		$dryRun = (bool)$input->getOption('dry-run');
		$allowDowngrade = (bool)$input->getOption('allow-downgrade');
		$acceptNewSha = (bool)$input->getOption('accept-new-sha');
		$forceIncompatible = (bool)$input->getOption('force-incompatible');

		$result = $this->installerService->installAppVersion(new InstallRequest(
			new InstallTarget($appId, $version, $sourceOverride),
			new InstallOptions(false, $dryRun, $acceptNewSha, $allowDowngrade, $forceIncompatible),
			new PinChoice(null, false),
		));

		$statusCode = $result['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR;
		/** @var array<string, mixed> $payload */
		$payload = $result['payload'] ?? [];
		$exitCode = $this->exitCodeFor($statusCode, $payload);

		if ($json) {
			$output->writeln(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

			return $exitCode;
		}

		$this->renderHuman($output, $exitCode, $payload);
		if ($forceIncompatible && $exitCode === self::EXIT_INCOMPATIBLE) {
			$this->errorOutput($output)->writeln('An override only lifts the declared maximum, so it cannot help this version: it still does not fit this server, for example because it needs a newer Nextcloud.');
		}

		return $exitCode;
	}

	private function renderHuman(OutputInterface $output, int $exitCode, array $payload): void {
		if ($exitCode === self::EXIT_OK) {
			$output->writeln(sprintf('<info>%s</info>', $payload['message'] ?? 'App installed.'));
			if (isset($payload['toVersion'])) {
				$output->writeln(sprintf('Version: %s', $payload['toVersion']));
			}
			if (isset($payload['updateType'])) {
				$output->writeln(sprintf('Update type: %s', $payload['updateType']));
			}

			return;
		}

		$error = $this->errorOutput($output);
		$error->writeln(sprintf('<error>%s</error>', $payload['message'] ?? 'Install failed.'));
		if (isset($payload['hint']) && is_string($payload['hint']) && $payload['hint'] !== '') {
			$error->writeln($payload['hint']);
		}
		if ($exitCode === self::EXIT_FINALIZE && isset($payload['installStatus'])) {
			// "8 finalize (check installStatus: reverted vs installed-but-broken,
			// printed explicitly)" — see design.md.
			$error->writeln(sprintf('installStatus: %s', $payload['installStatus']));
		}
	}

	/**
	 * @param array{appId: string, toVersion: string, message: string} $refusal
	 */
	private function reportRefusal(OutputInterface $output, bool $json, array $refusal): void {
		if ($json) {
			$output->writeln(json_encode($refusal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

			return;
		}

		$this->errorOutput($output)->writeln(sprintf('<error>%s</error>', $refusal['message']));
	}

	/**
	 * Maps the structured outcome from `InstallerService::installAppVersion()`
	 * to the documented exit code. The self/core guard is pre-filtered by
	 * this command (see execute()), so a 403 reaching here can only be the
	 * trusted-source allowlist guard — see "CLI trust context".
	 *
	 * @param array<string, mixed> $payload
	 */
	private function exitCodeFor(int $statusCode, array $payload): int {
		if ($statusCode === Http::STATUS_OK) {
			return self::EXIT_OK;
		}

		/** @var mixed $category */
		$category = $payload['category'] ?? null;
		if (is_string($category)) {
			$mapped = match ($category) {
				FailureClassifier::CATEGORY_DOWNGRADE_GUARD => self::EXIT_DOWNGRADE_REFUSED,
				FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION => self::EXIT_PREFLIGHT_PERMISSION,
				FailureClassifier::CATEGORY_DOWNLOAD => self::EXIT_DOWNLOAD,
				FailureClassifier::CATEGORY_CHECKSUM_MISMATCH,
				FailureClassifier::CATEGORY_SHA_MISMATCH,
				FailureClassifier::CATEGORY_APPID_MISMATCH,
				FailureClassifier::CATEGORY_VERSION_MISMATCH => self::EXIT_INTEGRITY,
				FailureClassifier::CATEGORY_INCOMPATIBLE => self::EXIT_INCOMPATIBLE,
				FailureClassifier::CATEGORY_FINALIZE => self::EXIT_FINALIZE,
				default => null,
			};
			if ($mapped !== null) {
				return $mapped;
			}
		}

		if ($statusCode === Http::STATUS_FORBIDDEN) {
			return self::EXIT_UNTRUSTED_SOURCE;
		}

		if ($statusCode === Http::STATUS_BAD_REQUEST || $statusCode === Http::STATUS_NOT_FOUND) {
			return self::EXIT_BAD_ARGUMENTS;
		}

		return self::EXIT_UNKNOWN;
	}

	private function errorOutput(OutputInterface $output): OutputInterface {
		return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
	}
}
