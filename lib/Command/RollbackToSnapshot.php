<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Command;

use OCA\Versioniq\Service\Snapshot\InstanceSnapshot;
use OCA\Versioniq\Service\Snapshot\RollbackRunLog;
use OCA\Versioniq\Service\Snapshot\RollbackRunner;
use OCA\Versioniq\Service\Snapshot\SnapshotPlans;
use OCA\Versioniq\Service\Snapshot\SnapshotStore;
use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `occ versioniq:rollback <snapshotId> | --to=<YYYY-MM-DD> [--yes]
 * [--dry-run] [--move-pins] [--continue]` (install-bulk-rollback design D5):
 * prints the plan, asks for confirmation unless `--yes`, takes a
 * `before_rollback` snapshot, records the start and the end of the run and
 * installs the steps through the standard installer. Exits 0 when every step
 * succeeded, else with the `versioniq:install` exit code of the first failed
 * step.
 *
 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
 * @psalm-api
 */
class RollbackToSnapshot extends Command {
	public function __construct(
		private SnapshotStore $store,
		private SnapshotTaker $taker,
		private SnapshotPlans $plans,
		private RollbackRunner $runner,
		private RollbackRunLog $runLog,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:rollback')
			->setDescription('Roll every app back to the versions of a snapshot.')
			->addArgument('snapshotId', InputArgument::OPTIONAL, 'The snapshot to roll back to.')
			->addOption('to', null, InputOption::VALUE_REQUIRED, 'A date (YYYY-MM-DD): use the newest snapshot taken on or before it.')
			->addOption('yes', 'y', InputOption::VALUE_NONE, 'Run without asking for confirmation.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check every downgrade and list the migrations it would orphan; change nothing.')
			->addOption('move-pins', null, InputOption::VALUE_NONE, 'Also move apps pinned to another version.')
			->addOption('continue', null, InputOption::VALUE_NONE, 'Go on with the other steps after a failure.')
			->setHelp('Exit codes: 0 when every step succeeded, 2 for bad arguments or an unknown snapshot, '
				. 'else the versioniq:install exit code of the first failed step.');
	}

	/**
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$snapshot = $this->resolveSnapshot($input, $output);
		if ($snapshot === null) {
			return InstallExitCode::BAD_ARGUMENTS;
		}

		$movePins = (bool)$input->getOption('move-pins');
		$steps = $this->plans->planFor($snapshot);
		$output->writeln(sprintf('Plan to roll back to snapshot %s (%s, %s):', $snapshot->id, $snapshot->takenAt, $snapshot->reason));
		$this->printPlan($output, $steps);
		$runnable = count(array_filter($steps, static fn (array $step): bool => RollbackRunner::isRunnable($step, $movePins)));

		if ((bool)$input->getOption('dry-run')) {
			$outcomes = $this->runner->run($steps, true, $movePins, true, fn (array $outcome) => $this->printCheck($output, $outcome));

			return $this->exitCode($outcomes);
		}
		if ($runnable === 0) {
			$output->writeln('<info>Nothing to roll back.</info>');

			return InstallExitCode::OK;
		}
		if (!(bool)$input->getOption('yes') && !$this->confirmed($input, $output, $runnable)) {
			$output->writeln('Cancelled; nothing changed.');

			return InstallExitCode::BAD_ARGUMENTS;
		}

		return $this->runPlan($output, $snapshot, $steps, $movePins, (bool)$input->getOption('continue'), $runnable);
	}

	/**
	 * @param list<array<string, mixed>> $steps
	 */
	private function runPlan(OutputInterface $output, InstanceSnapshot $snapshot, array $steps, bool $movePins, bool $continue, int $runnable): int {
		$before = $this->taker->take('before_rollback', 'system');
		$output->writeln(sprintf('Snapshot %s taken before the run.', $before->id));
		$this->runLog->started('system', $snapshot->id, $runnable);
		/** @psalm-suppress ArgumentTypeCoercion The steps come from the planner. */
		$outcomes = $this->runner->run($steps, false, $movePins, $continue, fn (array $outcome) => $this->printOutcome($output, $outcome));
		$counts = RollbackRunner::counts($outcomes);
		$this->runLog->finished('system', $snapshot->id, $counts['succeeded'], $counts['failed'], $counts['skipped']);
		$output->writeln(sprintf('%d succeeded, %d failed, %d skipped.', $counts['succeeded'], $counts['failed'], $counts['skipped']));

		return $this->exitCode($outcomes);
	}

	private function resolveSnapshot(InputInterface $input, OutputInterface $output): ?InstanceSnapshot {
		/** @var mixed $id */
		$id = $input->getArgument('snapshotId');
		/** @var mixed $to */
		$to = $input->getOption('to');
		$id = is_string($id) ? trim($id) : '';
		$to = is_string($to) ? trim($to) : '';
		if (($id === '') === ($to === '')) {
			$output->writeln('<error>Give either a snapshot id or --to=YYYY-MM-DD.</error>');

			return null;
		}
		if ($id !== '') {
			$snapshot = $this->store->get($id);
			if ($snapshot === null) {
				$output->writeln(sprintf('<error>Snapshot %s not found.</error>', $id));
			}

			return $snapshot;
		}

		$moment = SnapshotStore::endOfDay($to);
		if ($moment === null) {
			$output->writeln('<error>--to must be a date, YYYY-MM-DD.</error>');

			return null;
		}
		$snapshot = $this->store->latestAtOrBefore($moment);
		if ($snapshot === null) {
			$output->writeln(sprintf('<error>No snapshot taken on or before %s.</error>', $to));

			return null;
		}
		$output->writeln(sprintf('Using snapshot %s, the newest taken on or before %s.', $snapshot->id, $to));

		return $snapshot;
	}

	private function confirmed(InputInterface $input, OutputInterface $output, int $runnable): bool {
		if (!$input->isInteractive()) {
			$output->writeln('<error>Pass --yes to run without a confirmation prompt.</error>');

			return false;
		}
		return (bool)(new QuestionHelper())->ask($input, $output, new ConfirmationQuestion(sprintf('Install %d apps? [y/N] ', $runnable), false));
	}

	/**
	 * @param list<array<string, mixed>> $steps
	 */
	private function printPlan(OutputInterface $output, array $steps): void {
		$table = new Table($output);
		$table->setHeaders(['App', 'Step', 'Now', 'Snapshot', 'Pinned to']);
		foreach ($steps as $step) {
			$table->addRow([(string)$step['appId'], (string)$step['kind'], (string)($step['from'] ?? ''), (string)($step['to'] ?? ''), (string)($step['pinnedTo'] ?? '')]);
		}
		$table->render();
	}

	/**
	 * @param array<string, mixed> $outcome
	 */
	private function printCheck(OutputInterface $output, array $outcome): void {
		if ($outcome['outcome'] === 'skipped') {
			return;
		}
		if ($outcome['outcome'] === 'failed') {
			$this->printOutcome($output, $outcome);

			return;
		}
		$payload = is_array($outcome['payload']) ? $outcome['payload'] : [];
		$migrations = is_array($payload['orphanedMigrations'] ?? null) ? $payload['orphanedMigrations'] : [];
		$output->writeln(sprintf(
			'%s %s -> %s: %s',
			(string)$outcome['appId'],
			(string)$outcome['from'],
			(string)$outcome['to'],
			$migrations === [] ? 'no migrations orphaned' : 'would orphan ' . implode(', ', array_map('strval', $migrations)),
		));
	}

	/**
	 * @param array<string, mixed> $outcome
	 */
	private function printOutcome(OutputInterface $output, array $outcome): void {
		$payload = is_array($outcome['payload']) ? $outcome['payload'] : [];
		$message = is_string($payload['message'] ?? null) ? ': ' . $payload['message'] : '';
		$output->writeln(sprintf('%s %s -> %s %s%s', (string)$outcome['appId'], (string)$outcome['from'], (string)$outcome['to'], (string)$outcome['outcome'], $outcome['outcome'] === 'failed' ? $message : ''));
	}

	/**
	 * 0 when no step failed, else the install exit code of the first failure.
	 *
	 * @param list<array{outcome: string, statusCode: ?int, payload: array<array-key, mixed>}> $outcomes
	 */
	private function exitCode(array $outcomes): int {
		foreach ($outcomes as $outcome) {
			if ($outcome['outcome'] === 'failed') {
				return InstallExitCode::for((int)$outcome['statusCode'], $outcome['payload']);
			}
		}

		return InstallExitCode::OK;
	}
}
