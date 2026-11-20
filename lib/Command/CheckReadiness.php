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

use OCA\Versioniq\Service\Readiness\UpgradeReadinessService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:readiness --server=<major> --php=<version> [--json]`: the
 * upgrade readiness report for every installed app, run directly because the
 * CLI has the time (inventory-upgrade-readiness D4). It reports, it does not
 * judge: the exit code is 0 whatever the verdicts, 1 only for bad targets.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */
class CheckReadiness extends Command {
	private const VERDICT_LABELS = [
		'shipped' => 'Ships with the server',
		'ready' => 'Ready',
		'updateFirst' => 'Update first',
		'blocked' => 'Blocked',
		'unknown' => 'Unknown',
	];

	public function __construct(
		private UpgradeReadinessService $readiness,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('versioniq:readiness')
			->setDescription('Check every installed app against a target Nextcloud major and PHP version.')
			->addOption('server', null, InputOption::VALUE_REQUIRED, 'The target Nextcloud major, such as 33.')
			->addOption('php', null, InputOption::VALUE_REQUIRED, 'The target PHP version, such as 8.4. Defaults to the PHP this server runs.')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON instead of a table.');
	}

	/**
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$server = trim((string)$input->getOption('server'));
		$php = trim((string)($input->getOption('php') ?? ''));
		$php = $php !== '' ? $php : PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		if (preg_match('/^\d{1,3}$/', $server) !== 1 || preg_match('/^\d+\.\d+(\.\d+)?$/', $php) !== 1) {
			$output->writeln('<error>Give --server as a Nextcloud major such as 33, and --php as a version such as 8.4.</error>');
			return 1;
		}

		$report = $this->readiness->check($server, $php);
		if ((bool)$input->getOption('json')) {
			$output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
			return 0;
		}

		$this->printTable($output, $report);
		return 0;
	}

	/**
	 * @param array{server: string, php: string, apps: list<array<string, mixed>>} $report
	 */
	private function printTable(OutputInterface $output, array $report): void {
		$output->writeln(sprintf('Target: Nextcloud %s, PHP %s', $report['server'], $report['php']));
		$table = new Table($output);
		$table->setHeaders(['App', 'Installed', 'Verdict', 'Version to take']);
		$table->setRows(array_map(
			static fn (array $app): array => [
				$app['id'],
				$app['installedVersion'],
				self::VERDICT_LABELS[$app['verdict']] ?? $app['verdict'],
				$app['version'] ?? '',
			],
			$report['apps'],
		));
		$table->render();
	}
}
