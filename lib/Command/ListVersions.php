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
 * `occ versioniq:versions <appId> [--source=] [--json]` — lists the
 * versions available for an already-installed app from its bound (or
 * one-off overridden) source, the same resolution `InstallerService`
 * performs for the HTTP API; see "List versions from the CLI".
 *
 * @spec openspec/specs/cli-commands/spec.md
 * @psalm-api
 */
class ListVersions extends Command {
	public function __construct(
		private InstallerService $installerService,
	) {
		parent::__construct();
	}

	/**
	 * Declare the command name, arguments and options.
	 *
	 * The name moved from `app_versions:versions` to `versioniq:versions` with
	 * the app id — `occ` command prefixes follow the app, so an admin script
	 * on the old name breaks loudly rather than silently.
	 *
	 * @spec openspec/specs/cli-commands/spec.md
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:versions')
			->setDescription('List the versions available for an app from its bound (or overridden) source.')
			->addArgument('appId', InputArgument::REQUIRED, 'The app id to list versions for.')
			->addOption('source', null, InputOption::VALUE_REQUIRED, 'One-off source id override (e.g. github:owner/repo), instead of the app\'s bound source.')
			->addOption('json', null, InputOption::VALUE_NONE, 'Emit the result as JSON instead of a human-readable table.');
	}

	/**
	 * @spec openspec/specs/cli-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = trim((string)$input->getArgument('appId'));

		/** @var mixed $sourceOption */
		$sourceOption = $input->getOption('source');
		$sourceOverride = is_string($sourceOption) && trim($sourceOption) !== '' ? trim($sourceOption) : null;
		$json = (bool)$input->getOption('json');

		$result = $this->installerService->getAppVersions($appId, $sourceOverride);
		$statusCode = $result['statusCode'] ?? Http::STATUS_OK;
		$hasError = $result['hasError'] ?? false;
		$error = $result['error'] ?? null;
		unset($result['statusCode'], $result['hasError']);

		if ($json) {
			$output->writeln((string)json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		} else {
			$this->renderTable($output, $appId, $result);
		}

		if ($statusCode !== Http::STATUS_OK || $hasError) {
			$this->errorOutput($output)->writeln(sprintf('<error>%s</error>', $error ?? 'Failed to list versions for "' . $appId . '".'));

			return 1;
		}

		return 0;
	}

	/**
	 * @param array{installedVersion:?string, availableVersions:list<array{version:string,changelog?:?string,recordedSha?:?string,serverCompatible?:?bool}>, versions:list<array{version:string,changelog?:?string,recordedSha?:?string}>, source:string, sourceId:string, error?:string} $result
	 */
	private function renderTable(OutputInterface $output, string $appId, array $result): void {
		$installedVersion = $result['installedVersion'] ?? null;

		$output->writeln(sprintf('App: %s', $appId));
		$output->writeln(sprintf('Source: %s', $result['sourceId'] ?? 'none'));
		$output->writeln(sprintf('Installed version: %s', $installedVersion ?? '(not installed)'));

		$availableVersions = $result['availableVersions'] ?? [];
		if ($availableVersions === []) {
			$output->writeln('No versions available.');

			return;
		}

		$rows = [];
		foreach ($availableVersions as $entry) {
			$version = is_string($entry['version'] ?? null) ? $entry['version'] : '';
			$rows[] = [
				$version,
				$this->compatibilityMarker($version, $installedVersion),
				$this->serverCompatibilityMarker($entry['serverCompatible'] ?? null),
				$entry['recordedSha'] ?? '',
			];
		}

		$table = new Table($output);
		$table->setHeaders(['Version', 'Relative to installed', 'Runs on this server', 'Recorded SHA-256']);
		$table->setRows($rows);
		$table->render();
	}

	/**
	 * Marks each listed version relative to the installed version — the
	 * "compatibility marker" required by "List versions from the CLI".
	 */
	private function compatibilityMarker(string $version, ?string $installedVersion): string {
		if ($installedVersion === null || $installedVersion === '' || $version === '') {
			return '';
		}
		if (version_compare($version, $installedVersion, '=')) {
			return 'installed';
		}

		return version_compare($version, $installedVersion, '>') ? 'newer' : 'older';
	}

	/**
	 * Says whether a version runs on this server: `yes`, `no`, or `unknown`
	 * when the source does not say (a forge release, or an App Store release
	 * without a platform range); see "List versions from the CLI".
	 *
	 * @spec openspec/specs/cli-commands/spec.md
	 */
	private function serverCompatibilityMarker(?bool $serverCompatible): string {
		if ($serverCompatible === null) {
			return 'unknown';
		}

		return $serverCompatible ? 'yes' : 'no';
	}

	private function errorOutput(OutputInterface $output): OutputInterface {
		return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
	}
}
