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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:install <appId> <version> [--source=] [--dry-run]
 * [--allow-downgrade] [--json]` — installs a specific version through the
 * same `InstallerService::installAppVersion()` path the HTTP API uses
 * (source resolution, allowlist, integrity verification, backup/restore,
 * maintenance-mode, finalize) — no duplicated install logic.
 *
 * CLI execution runs as the server user with full config access — the trust
 * context is equivalent to `occ app:install`, so no password confirmation
 * applies; see "CLI trust context".
 *
 * @spec openspec/specs/cli-commands/spec.md
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

	/** What each Nextcloud migration attribute does, by short class name. */
	private const ACTION_PHRASES = [
		'CreateTable' => 'creates a table',
		'DropTable' => 'drops a table',
		'AddColumn' => 'adds a column',
		'DropColumn' => 'drops a column',
		'ModifyColumn' => 'changes a column',
		'AddIndex' => 'adds an index',
		'DropIndex' => 'drops an index',
		'DataCleansing' => 'cleans data',
	];

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

		$result = $this->installerService->installAppVersion(new InstallRequest(
			new InstallTarget($appId, $version, $sourceOverride),
			new InstallOptions(false, $dryRun, $acceptNewSha, $allowDowngrade),
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
			if (array_key_exists('migrationPreview', $payload)) {
				$this->renderMigrationPreview($output, is_array($payload['migrationPreview']) ? $payload['migrationPreview'] : null);
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
	 * The database changes of an upgrade dry run: one row per table a step
	 * changes, with its rows and advice, then the repair steps. No duration
	 * in seconds is printed; see design.md D3 and D5 of the
	 * `safety-upgrade-migration-preview` change.
	 *
	 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
	 * @param array<array-key, mixed>|null $preview
	 */
	private function renderMigrationPreview(OutputInterface $output, ?array $preview): void {
		if ($preview === null) {
			$output->writeln('Database changes: the target could not be read.');

			return;
		}

		$steps = self::arrays($preview['steps'] ?? []);
		$estimated = ($preview['estimated'] ?? false) === true ? ' (estimate: the migrations table could not be read)' : '';
		if ($steps === []) {
			$output->writeln('No database changes' . $estimated . '.');
		} else {
			$output->writeln('Database changes' . $estimated . ':');
			$table = new Table($output);
			$table->setHeaders(['Step', 'Changes', 'Table', 'Rows', 'Advice']);
			foreach ($steps as $step) {
				$table->addRows(self::stepRows($step));
			}
			$table->render();
		}

		$repairSteps = self::strings($preview['repairSteps'] ?? []);
		if ($repairSteps !== []) {
			$output->writeln('Repair steps after the migrations: ' . implode(', ', $repairSteps));
		}
	}

	/**
	 * The table rows of one step: one per table it changes, or one row
	 * saying what it does without a table.
	 *
	 * @param array<array-key, mixed> $step
	 * @return list<list<string>>
	 */
	private static function stepRows(array $step): array {
		$name = (string)($step['name'] ?? '');
		$changes = self::stepChanges($step);
		$data = ($step['dataStep'] ?? false) === true ? 'Moves data; the time depends on the amount of data.' : '';
		$tables = self::arrays($step['tables'] ?? []);
		if ($tables === []) {
			return [[$name, $changes, '', '', $data]];
		}

		$rows = [];
		foreach ($tables as $table) {
			$advice = trim(self::sizeAdvice((string)($table['sizeClass'] ?? '')) . ' ' . $data);
			/** @var mixed $count */
			$count = $table['rows'] ?? null;
			$rowCount = is_int($count) ? number_format($count) : '';
			$rows[] = [$name, $changes, (string)($table['name'] ?? ''), $rowCount, $advice];
		}

		return $rows;
	}

	/**
	 * What a step declares, as short phrases; a step that describes nothing says so.
	 *
	 * @param array<array-key, mixed> $step
	 */
	private static function stepChanges(array $step): string {
		if (($step['describes'] ?? true) === false) {
			return 'This step does not describe what it changes.';
		}

		$phrases = [];
		foreach (self::arrays($step['actions'] ?? []) as $action) {
			$attribute = (string)($action['attribute'] ?? '');
			$phrases[] = self::ACTION_PHRASES[$attribute] ?? $attribute;
		}

		return implode(', ', array_values(array_unique($phrases)));
	}

	/**
	 * The array entries of a list-shaped value.
	 *
	 * @return list<array<array-key, mixed>>
	 */
	private static function arrays(mixed $value): array {
		$out = [];
		/** @var mixed $entry */
		foreach (is_array($value) ? $value : [] as $entry) {
			if (is_array($entry)) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/**
	 * The string entries of a list-shaped value.
	 *
	 * @return list<string>
	 */
	private static function strings(mixed $value): array {
		$out = [];
		/** @var mixed $entry */
		foreach (is_array($value) ? $value : [] as $entry) {
			if (is_string($entry)) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/** The advice for a size class; never a duration in seconds. */
	private static function sizeAdvice(string $sizeClass): string {
		return match ($sizeClass) {
			'new' => 'New table; fast.',
			'small' => 'Takes seconds.',
			'large' => 'May take minutes.',
			'very_large' => 'May take long; plan a maintenance window.',
			default => 'Size unknown.',
		};
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
