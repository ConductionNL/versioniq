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

use OCA\Versioniq\Kernel\Contracts\AdvisoryResultStoreInterface;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Advisory\ComplianceStatus;
use OCA\Versioniq\Service\Advisory\FixStatus;
use OCA\Versioniq\Service\Pin\PinStore;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:advisories [--json] [--app <id>] [--check]`: the patch
 * compliance status and, per target, the advisories affecting the installed
 * version and the recent ones, from the stored advisory snapshot. It never
 * calls a source (design D5 of advisories-per-app-detail).
 *
 * @spec openspec/changes/advisories-per-app-detail/specs/cli-commands/spec.md#requirement-read-advisories-and-the-compliance-status-from-the-cli
 * @psalm-api
 */
class ListAdvisories extends Command {
	/** Exit codes of --check, so a monitoring script can alert on them. */
	public const EXIT_COMPLIANT = 0;
	public const EXIT_NOT_COMPLIANT = 1;
	public const EXIT_UNKNOWN = 2;

	/** How many of a target's most recent advisories the table shows next to the affecting ones. */
	private const RECENT = 5;

	public function __construct(
		private AdvisoryResultStoreInterface $resultStore,
		private AdvisorySettingsStore $settings,
		private PinStore $pins,
		private ITimeFactory $time,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:advisories')
			->setDescription('Show the patch compliance status and the security advisories of the server and every app from the last advisory check.')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON instead of a table.')
			->addOption('app', null, InputOption::VALUE_REQUIRED, 'Show only this app, or the server with :server.')
			->addOption('check', null, InputOption::VALUE_NONE, 'Exit 0 when compliant, 1 when not compliant, 2 when unknown.');
	}

	/**
	 * @spec openspec/changes/advisories-per-app-detail/specs/cli-commands/spec.md#requirement-read-advisories-and-the-compliance-status-from-the-cli
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$snapshot = $this->resultStore->read();
		$rows = FixStatus::withPins($snapshot['advisories'], $this->pins->all());
		$compliance = ComplianceStatus::from(
			$rows,
			$snapshot['checkedAt'],
			$this->settings->getIntervalHours(),
			$this->time->getTime(),
		);

		$rows = self::onlyApp($rows, $input->getOption('app'));

		if ((bool)$input->getOption('json')) {
			$output->writeln(json_encode([
				'compliance' => $compliance,
				'checkedAt' => $snapshot['checkedAt'],
				'targets' => $rows,
			], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		} else {
			$output->writeln(self::statusLine($compliance));
			$this->printTable($output, $rows);
		}

		return (bool)$input->getOption('check') ? self::exitCode($compliance['status']) : 0;
	}

	/**
	 * The rows of the one app the --app option names, or all rows without it.
	 *
	 * @param array<array-key, mixed> $rows
	 * @return array<array-key, mixed>
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	private static function onlyApp(array $rows, mixed $app): array {
		if (!is_string($app) || $app === '') {
			return $rows;
		}

		return array_key_exists($app, $rows) ? [$app => $rows[$app]] : [];
	}

	/**
	 * The status in one line, as the Apps and Advisories tabs say it.
	 *
	 * @param array{status: string, reason: ?string, affected: int, bySeverity: array<string, int>, affectedIds?: list<string>, unreached: int, checkedAt?: ?int} $compliance
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	public static function statusLine(array $compliance): string {
		if ($compliance['status'] === ComplianceStatus::COMPLIANT) {
			return 'Compliant: no installed version is affected by a known advisory.';
		}
		if ($compliance['status'] === ComplianceStatus::NOT_COMPLIANT) {
			return self::notCompliantLine($compliance['affected'], $compliance['bySeverity']);
		}

		return match ($compliance['reason']) {
			ComplianceStatus::REASON_NEVER_CHECKED => 'Unknown: no advisory check has completed yet.',
			ComplianceStatus::REASON_STALE => 'Unknown: the last advisory check is out of date.',
			default => sprintf('Unknown: %d %s could not be checked.', $compliance['unreached'], $compliance['unreached'] === 1 ? 'app' : 'apps'),
		};
	}

	/**
	 * The exit code of --check for a status.
	 *
	 * @spec openspec/changes/advisories-per-app-detail/specs/cli-commands/spec.md#requirement-read-advisories-and-the-compliance-status-from-the-cli
	 */
	private static function exitCode(string $status): int {
		return match ($status) {
			ComplianceStatus::COMPLIANT => self::EXIT_COMPLIANT,
			ComplianceStatus::NOT_COMPLIANT => self::EXIT_NOT_COMPLIANT,
			default => self::EXIT_UNKNOWN,
		};
	}

	/**
	 * @param array<array-key, mixed> $rows
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	private function printTable(OutputInterface $output, array $rows): void {
		$table = new Table($output);
		$table->setHeaders(['Target', 'Installed', 'Advisory', 'CVE', 'Published', 'Severity', 'Installed version']);
		$table->setRows($this->tableRows($rows));
		$table->render();
	}

	/**
	 * Per target its affecting advisories and the most recent others.
	 *
	 * @param array<array-key, mixed> $rows
	 * @return list<list<string>>
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	private function tableRows(array $rows): array {
		$lines = [];
		/** @var mixed $row */
		foreach ($rows as $target => $row) {
			if (is_array($row)) {
				$lines = array_merge($lines, $this->targetLines((string)$target, $row));
			}
		}

		return $lines;
	}

	/**
	 * The table lines of one target: its affecting advisories and the most
	 * recent others, or a "not checked" line when it has no history but an error.
	 *
	 * @param array<array-key, mixed> $row
	 * @return list<list<string>>
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	private function targetLines(string $target, array $row): array {
		/** @var mixed $history */
		$history = $row['history'] ?? null;
		$history = is_array($history) ? $history : [];
		$head = [$target, (string)($row['installedVersion'] ?? ''), self::fixNote($row)];
		$lines = array_map(fn (array $advisory): array => $this->advisoryLine($head, $advisory), self::shownAdvisories($history));
		/** @var mixed $error */
		$error = $row['error'] ?? null;
		if ($history === [] && is_string($error)) {
			$lines[] = [$target, (string)($row['installedVersion'] ?? ''), '', '', '', '', 'not checked: ' . $error];
		}

		return $lines;
	}

	/**
	 * The advisories of a history the table shows: every affecting one, and
	 * others while fewer than RECENT advisories are shown.
	 *
	 * @param array<array-key, mixed> $history
	 * @return list<array<array-key, mixed>>
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	private static function shownAdvisories(array $history): array {
		$shown = [];
		/** @var mixed $advisory */
		foreach ($history as $advisory) {
			if (!is_array($advisory)) {
				continue;
			}
			if (($advisory['affectsInstalled'] ?? false) === true || count($shown) < self::RECENT) {
				$shown[] = $advisory;
			}
		}

		return $shown;
	}

	/**
	 * One advisory of a target as a table line.
	 *
	 * @param array{0: string, 1: string, 2: string} $head The target, its installed version and its fix note
	 * @param array<array-key, mixed> $advisory
	 * @return list<string>
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	private function advisoryLine(array $head, array $advisory): array {
		/** @var mixed $cves */
		$cves = $advisory['cveIds'] ?? null;
		/** @var mixed $date */
		$date = $advisory['publishedAt'] ?? null;
		$affects = ($advisory['affectsInstalled'] ?? false) === true;

		return [
			$head[0],
			$head[1],
			(string)($advisory['id'] ?? ''),
			is_array($cves) && $cves !== [] ? implode(', ', array_map(strval(...), array_filter($cves, is_string(...)))) : 'no CVE id',
			is_string($date) ? substr($date, 0, 10) : 'date unknown',
			(string)($advisory['severity'] ?? ''),
			$affects ? 'affected' . $head[2] : 'fixed',
		];
	}

	/**
	 * The reason an affected target has no installable fix, after a dash.
	 *
	 * @param array<array-key, mixed> $row
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	private static function fixNote(array $row): string {
		if (($row['heldByPin'] ?? false) === true) {
			return ' - a pin holds it below the fix ' . (string)($row['recommendedVersion'] ?? '');
		}

		return match ($row['fixStatus'] ?? null) {
			FixStatus::INSTALLABLE => ' - fix ' . (string)($row['recommendedVersion'] ?? ''),
			FixStatus::NO_FIX_PUBLISHED => ' - no fixed version is published yet',
			FixStatus::NOT_IN_SOURCE => ' - the fix is not offered by the bound source',
			FixStatus::NEEDS_NEWER_SERVER => ' - the fix needs a newer Nextcloud',
			default => '',
		};
	}

	/**
	 * The status line of a non-compliant instance, with the counts per severity.
	 *
	 * @param array<string, int> $bySeverity
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#5.1
	 */
	private static function notCompliantLine(int $affected, array $bySeverity): string {
		$counts = [];
		foreach ($bySeverity as $severity => $count) {
			$counts[] = $count . ' ' . $severity;
		}

		return sprintf('Not compliant: %d %s an affected version (%s).', $affected, $affected === 1 ? 'app runs' : 'apps run', implode(', ', $counts));
	}
}
