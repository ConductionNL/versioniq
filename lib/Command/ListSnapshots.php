<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Command;

use OCA\Versioniq\Service\Snapshot\SnapshotStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:snapshots [--json]`: lists the snapshots, newest first
 * (install-bulk-rollback design D5).
 *
 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
 * @psalm-api
 */
class ListSnapshots extends Command {
	public function __construct(
		private SnapshotStore $store,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:snapshots')
			->setDescription('List the snapshots of every app\'s version, newest first.')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the list as JSON instead of a table.');
	}

	/**
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$index = $this->store->index();
		if ((bool)$input->getOption('json')) {
			$output->writeln(json_encode($index, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

			return InstallExitCode::OK;
		}

		$table = new Table($output);
		$table->setHeaders(['Id', 'Taken at', 'Reason', 'Name', 'Apps']);
		foreach ($index as $line) {
			$table->addRow([$line['id'], $line['takenAt'], $line['reason'], $line['name'] ?? '', (string)$line['appCount']]);
		}
		$table->render();

		return InstallExitCode::OK;
	}
}
