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

use OCA\Versioniq\Service\Inventory\SbomExporter;
use OCA\Versioniq\Service\Inventory\SbomWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:sbom --format=cyclonedx|spdx [--output=<file>]`: the current
 * inventory as an SBOM, on stdout or into a file (inventory-export-sbom-metrics D5).
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
 */
class ExportSbom extends Command {
	public function __construct(
		private SbomExporter $exporter,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:sbom')
			->setDescription('Export the server and every installed app as an SBOM (CycloneDX 1.5 or SPDX 2.3 JSON).')
			->addOption('format', null, InputOption::VALUE_REQUIRED, 'cyclonedx or spdx', SbomWriter::FORMAT_CYCLONEDX)
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the SBOM to this file instead of printing it.');
	}

	/**
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-an-admin-exports-the-inventory-as-an-sbom
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$format = (string)$input->getOption('format');
		if (!in_array($format, SbomWriter::FORMATS, true)) {
			$this->errorOutput($output)->writeln('<error>--format must be one of: ' . implode(', ', SbomWriter::FORMATS) . '.</error>');
			return 1;
		}

		$json = $this->exporter->export($format);
		$file = (string)($input->getOption('output') ?? '');
		if ($file === '') {
			$output->writeln($json, OutputInterface::OUTPUT_RAW);
			return 0;
		}

		if (@file_put_contents($file, $json . "\n") === false) {
			$this->errorOutput($output)->writeln('<error>Could not write ' . $file . '.</error>');
			return 1;
		}

		$output->writeln('Wrote the ' . $format . ' SBOM to ' . $file . '.');
		return 0;
	}

	private function errorOutput(OutputInterface $output): OutputInterface {
		return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
	}
}
