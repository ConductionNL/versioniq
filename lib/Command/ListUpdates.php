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

use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseSweepRecorder;
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
		private ReleaseSweepRecorder $recorder,
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
		$this->refreshIfAsked($input);
		$snapshot = $this->recorder->read();
		if ($snapshot['checkedAt'] === null) {
			return $this->neverChecked($output);
		}

		$view = $this->view($snapshot, (bool)$input->getOption('outside-policy'));
		if ((bool)$input->getOption('json')) {
			$output->writeln(json_encode($view, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		} else {
			$this->printTable($output, $view);
		}

		return 0;
	}

	/**
	 * Sweeps and stores a fresh snapshot first when --refresh is given.
	 */
	private function refreshIfAsked(InputInterface $input): void {
		if ((bool)$input->getOption('refresh')) {
			$this->recorder->record($this->availabilityService->sweep(self::REFRESH_BUDGET_SECONDS), $this->time->getTime());
		}
	}

	/**
	 * Says no update check has run yet; returns the exit code for that.
	 */
	private function neverChecked(OutputInterface $output): int {
		$this->errorOutput($output)->writeln('<error>No update check has run yet. Run with --refresh, or wait for the background job.</error>');

		return 1;
	}

	/**
	 * What the command prints: the snapshot time, the lag limit and the
	 * apps, only those past the limit when $outsidePolicy is set.
	 *
	 * @param array{updates: array<string, mixed>, checkedAt: int} $snapshot
	 * @return array{checkedAt: int, maxLinesBehind: ?int, updates: array<string, array<string, mixed>>}
	 */
	private function view(array $snapshot, bool $outsidePolicy): array {
		$limit = $this->instanceSettings->maxLinesBehind();
		/** @var array<string, array<string, mixed>> $updates */
		$updates = $snapshot['updates'];

		return [
			'checkedAt' => $snapshot['checkedAt'],
			'maxLinesBehind' => $limit,
			'updates' => $outsidePolicy ? self::outsidePolicy($updates, $limit) : $updates,
		];
	}

	/**
	 * The apps more release lines behind than the limit; none when the
	 * check is off.
	 *
	 * @param array<string, array<string, mixed>> $updates
	 * @return array<string, array<string, mixed>>
	 */
	private static function outsidePolicy(array $updates, ?int $limit): array {
		return array_filter(
			$updates,
			static fn (array $entry): bool => $limit !== null && (int)($entry['linesBehind'] ?? 0) > $limit,
		);
	}

	/**
	 * @param array{checkedAt: int, maxLinesBehind: ?int, updates: array<string, array<string, mixed>>} $view
	 */
	private function printTable(OutputInterface $output, array $view): void {
		$output->writeln(sprintf('Last checked: %s', gmdate('Y-m-d H:i', $view['checkedAt']) . ' UTC'));
		$table = new Table($output);
		$table->setHeaders(['App', 'Installed', 'Newest this server runs', 'Releases behind', 'Pin']);
		$table->setRows($this->rows($view['updates']));
		$table->render();
	}

	/**
	 * @param array<string, array<string, mixed>> $updates
	 * @return list<list<string>>
	 */
	private function rows(array $updates): array {
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

		return $rows;
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
