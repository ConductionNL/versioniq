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

use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:auto-update --dry-run [--json]`: the plan of the next
 * automatic update run, live against the sources (autoupdate-proposed-updates
 * D3). The command has no mode that installs; the nightly job stays the only
 * automatic installer.
 *
 * @spec openspec/changes/autoupdate-proposed-updates/specs/cli-commands/spec.md#requirement-dry-run-the-automatic-update-policy-from-the-cli
 */
class AutoUpdate extends Command {
	public function __construct(
		private AutoUpdatePlanner $planner,
		private AutoUpdateSettingsStore $settings,
		private ITimeFactory $time,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('versioniq:auto-update')
			->setDescription('Show what the next automatic update run would install, without installing anything.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Plan the next run live against the sources. Required: the command never installs.')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the plan as JSON instead of a table.');
	}

	/**
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/cli-commands/spec.md#requirement-dry-run-the-automatic-update-policy-from-the-cli
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!(bool)$input->getOption('dry-run')) {
			$this->errorOutput($output)->writeln('<error>Only --dry-run is supported: the nightly job is the only automatic installer.</error>');

			return 2;
		}

		$enabled = $this->settings->isEnabled();
		$opening = $this->nextOpening();
		$plan = $this->planner->plan($opening ?? $this->now());

		if ((bool)$input->getOption('json')) {
			$output->writeln(json_encode([
				'autoUpdateEnabled' => $enabled,
				'plannedFor' => $opening?->format(\DateTimeInterface::ATOM),
				'apps' => $plan,
			], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		} else {
			$this->printTable($output, $enabled, $opening, $plan);
		}

		return $enabled ? 0 : 1;
	}

	private function now(): \DateTimeImmutable {
		$zone = $this->settings->getTimeZoneName();

		return \DateTimeImmutable::createFromInterface(
			$this->time->getDateTime('now', new \DateTimeZone($zone !== '' ? $zone : 'UTC'))
		);
	}

	/** The opening the next run happens in: the current one when inside the window. */
	private function nextOpening(): ?\DateTimeImmutable {
		$now = $this->now();
		$window = $this->settings->getWindow();
		if (AutoUpdateWindow::isWithin($window, $now)) {
			return $now;
		}

		return AutoUpdateWindow::nextOpening($window, $now);
	}

	/**
	 * @param list<array{appId: string, level: string, fromVersion: ?string, toVersion: ?string, action: string, reason: ?string}> $plan
	 */
	private function printTable(OutputInterface $output, bool $enabled, ?\DateTimeImmutable $opening, array $plan): void {
		if (!$enabled) {
			$output->writeln('<comment>Automatic updates are off: nothing below runs until they are enabled.</comment>');
		}
		$output->writeln('Next run: ' . ($opening?->format('Y-m-d H:i T') ?? 'the window never opens'));
		if ($plan === []) {
			$output->writeln('No app has an automatic update policy.');

			return;
		}

		$table = new Table($output);
		$table->setHeaders(['App', 'Policy', 'Installed', 'Would install', 'Reason']);
		foreach ($plan as $row) {
			$table->addRow([
				$row['appId'],
				$row['level'],
				$row['fromVersion'] ?? '-',
				$row['action'] === AutoUpdatePlanner::ACTION_INSTALL ? (string)$row['toVersion'] : '-',
				$row['reason'] === null ? '' : $row['reason'] . ($row['toVersion'] !== null && $row['action'] !== AutoUpdatePlanner::ACTION_INSTALL ? ' (' . $row['toVersion'] . ')' : ''),
			]);
		}
		$table->render();
	}

	private function errorOutput(OutputInterface $output): OutputInterface {
		return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
	}
}
