<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Command;

use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:snapshot [--name=]`: takes a snapshot of every managed app's
 * version (install-bulk-rollback design D5).
 *
 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
 * @psalm-api
 */
class TakeSnapshot extends Command {
	public function __construct(
		private SnapshotTaker $taker,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:snapshot')
			->setDescription('Take a snapshot of the version of every app Versioniq manages.')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'A name; a named snapshot is kept until it is deleted.');
	}

	/**
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		/** @var mixed $name */
		$name = $input->getOption('name');
		try {
			$snapshot = $this->taker->take('manual', 'system', is_string($name) ? $name : null);
		} catch (\OverflowException $error) {
			$output->writeln('<error>' . $error->getMessage() . '</error>');

			return InstallExitCode::BAD_ARGUMENTS;
		}

		$output->writeln(sprintf('<info>Snapshot %s taken of %d apps.</info>', $snapshot->id, count($snapshot->apps)));

		return InstallExitCode::OK;
	}
}
