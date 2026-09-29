<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Command;

use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:updates [--json] [--refresh] [--outside-policy]`: lists the
 * pending updates of every app from the stored availability snapshot, so a
 * script can ask "what is behind" across the instance in one call.
 *
 * @spec openspec/specs/cli-commands/spec.md#requirement-list-pending-updates-from-the-cli
 * @psalm-api
 */
class ListUpdates extends Command {
	/** A sweep started from the CLI has someone waiting on it; it still gets the job's budget. */
	private const REFRESH_BUDGET_SECONDS = 600.0;

	public function __construct(
		private AvailabilityService $availabilityService,
		private AvailabilityResultStore $resultStore,
		private InstanceSettings $instanceSettings,
		private ITimeFactory $time,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/specs/cli-commands/spec.md#requirement-list-pending-updates-from-the-cli
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:updates')
			->setDescription('List the pending updates of every app from the last update check.')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON instead of a table.')
			->addOption('refresh', null, InputOption::VALUE_NONE, 'Run the update check first and store it.')
			->addOption('outside-policy', null, InputOption::VALUE_NONE, 'List only apps that are more release lines behind than the limit set on the Settings tab.');
	}

	/**
	 * @spec openspec/specs/cli-commands/spec.md#requirement-list-pending-updates-from-the-cli
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ((bool)$input->getOption('refresh')) {
			$this->resultStore->save($this->availabilityService->sweep(self::REFRESH_BUDGET_SECONDS), $this->time->getTime());
		}

		$snapshot = $this->resultStore->read();
		if ($snapshot['checkedAt'] === null) {
			$this->errorOutput($output)->writeln('<error>No update check has run yet. Run with --refresh, or wait for the background job.</error>');

			return 1;
		}

		$limit = $this->instanceSettings->maxLinesBehind();
		/** @var array<string, array<string, mixed>> $updates */
		$updates = $snapshot['updates'];
		if ((bool)$input->getOption('outside-policy')) {
			$updates = array_filter(
				$updates,
				static fn (array $entry): bool => $limit !== null && (int)($entry['linesBehind'] ?? 0) > $limit,
			);
		}

		if ((bool)$input->getOption('json')) {
			$output->writeln(json_encode(
				['checkedAt' => $snapshot['checkedAt'], 'maxLinesBehind' => $limit, 'updates' => $updates],
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
			));

			return 0;
		}

		$output->writeln(sprintf('Last checked: %s', gmdate('Y-m-d H:i', $snapshot['checkedAt']) . ' UTC'));
		$rows = [];
		foreach ($updates as $appId => $entry) {
			$rows[] = [
				$appId,
				(string)($entry['installedVersion'] ?? ''),
				$this->statusOf($entry),
				(string)($entry['linesBehind'] ?? 0),
				($entry['pinned'] ?? false) === true ? 'pinned' : '',
			];
		}
		$table = new Table($output);
		$table->setHeaders(['App', 'Installed', 'Newest this server runs', 'Releases behind', 'Pin']);
		$table->setRows($rows);
		$table->render();

		return 0;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private function statusOf(array $entry): string {
		if (is_string($entry['error'] ?? null)) {
			return 'not checked: ' . (string)$entry['error'];
		}
		if (($entry['updateAvailable'] ?? false) === true) {
			return (string)($entry['newestCompatibleVersion'] ?? '');
		}

		return 'up to date';
	}

	private function errorOutput(OutputInterface $output): OutputInterface {
		return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
	}
}
