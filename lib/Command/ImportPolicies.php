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

use OCA\Versioniq\Service\Policy\PolicyChange;
use OCA\Versioniq\Service\Policy\PolicyFile;
use OCA\Versioniq\Service\Policy\PolicyFileException;
use OCA\Versioniq\Service\Policy\PolicyFileSync;
use OCA\Versioniq\Service\Policy\PolicySource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:policies:import <file> [--dry-run] [--prune]`: validates a
 * policy file, prints what it changes, and, without `--dry-run`, applies it
 * through the stores. `--prune` also removes stored per-app policies the file
 * does not name. Refused while `config.php` names a policy file, because the
 * stores are not read then.
 *
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 * @psalm-api
 */
class ImportPolicies extends Command {
	public function __construct(
		private PolicyFileSync $sync,
		private PolicySource $source,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:policies:import')
			->setDescription('Apply a policy file to the automatic update settings and per-app policies.')
			->addArgument('file', InputArgument::REQUIRED, 'The policy file to apply.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would change and write nothing.')
			->addOption('prune', null, InputOption::VALUE_NONE, 'Also remove stored per-app policies the file does not name.');
	}

	/**
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$managedBy = $this->source->filePath();
		if ($managedBy !== null) {
			$output->writeln('<error>The policies are read from ' . $managedBy . ' (versioniq.policy_file in config.php); edit that file instead.</error>');
			return 1;
		}

		try {
			$file = PolicyFile::read((string)$input->getArgument('file'));
		} catch (PolicyFileException $error) {
			foreach ($error->errors as $problem) {
				$output->writeln('<error>' . $problem . '</error>');
			}
			return 1;
		}

		$changes = $this->sync->plan($file, (bool)$input->getOption('prune'));
		$this->printChanges($output, $changes);
		if ($changes === [] || (bool)$input->getOption('dry-run')) {
			return 0;
		}

		$this->sync->apply($changes);
		$output->writeln('Applied ' . count($changes) . ' change(s).');

		return 0;
	}

	/**
	 * @param list<PolicyChange> $changes
	 */
	private function printChanges(OutputInterface $output, array $changes): void {
		if ($changes === []) {
			$output->writeln('Nothing to change.');
			return;
		}
		foreach ($changes as $change) {
			$output->writeln($change->describe());
		}
	}
}
