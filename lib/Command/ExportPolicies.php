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

use OCA\Versioniq\Service\Policy\PolicyFile;
use OCA\Versioniq\Service\Policy\PolicyFileSync;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:policies:export [<file>]`: writes the kill switch, the
 * window, the default level and every per-app policy as a policy file, to
 * `<file>` or, without one, to standard output.
 *
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 * @psalm-api
 */
class ExportPolicies extends Command {
	public function __construct(
		private PolicyFileSync $sync,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:policies:export')
			->setDescription('Write the automatic update settings and per-app policies to a policy file.')
			->addArgument('file', InputArgument::OPTIONAL, 'The file to write; standard output when omitted.');
	}

	/**
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$json = PolicyFile::encode($this->sync->export());
		$file = $input->getArgument('file');
		if (!is_string($file) || $file === '') {
			$output->write($json);
			return 0;
		}

		if (@file_put_contents($file, $json) === false) {
			$output->writeln('<error>Cannot write ' . $file . '.</error>');
			return 1;
		}
		$output->writeln('Wrote the policies to ' . $file . '.');

		return 0;
	}
}
