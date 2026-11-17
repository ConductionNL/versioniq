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

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console lines of an upgrade dry run's migration preview, as
 * `occ versioniq:install --dry-run` prints them: one row per table a step
 * changes, with its rows and advice, then the repair steps.
 *
 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
 */
final class MigrationPreviewOutput {
	/** What each Nextcloud migration attribute does, by short class name. */
	private const ACTION_PHRASES = [
		'CreateTable' => 'creates a table',
		'DropTable' => 'drops a table',
		'AddColumn' => 'adds a column',
		'DropColumn' => 'drops a column',
		'ModifyColumn' => 'changes a column',
		'AddIndex' => 'adds an index',
		'DropIndex' => 'drops an index',
		'DataCleansing' => 'cleans data',
	];

	/**
	 * Print the database changes of an upgrade dry run. No duration in
	 * seconds is printed; see design.md D3 and D5 of the
	 * `safety-upgrade-migration-preview` change.
	 *
	 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
	 * @param array<array-key, mixed>|null $preview The preview, null when the target could not be read.
	 */
	public static function render(OutputInterface $output, ?array $preview): void {
		if ($preview === null) {
			$output->writeln('Database changes: the target could not be read.');

			return;
		}

		self::renderSteps($output, $preview);

		$repairSteps = self::strings($preview['repairSteps'] ?? []);
		if ($repairSteps !== []) {
			$output->writeln('Repair steps after the migrations: ' . implode(', ', $repairSteps));
		}
	}

	/**
	 * The heading and the table of migration steps, or the line saying there are none.
	 *
	 * @param array<array-key, mixed> $preview
	 */
	private static function renderSteps(OutputInterface $output, array $preview): void {
		$steps = self::arrays($preview['steps'] ?? []);
		$estimated = ($preview['estimated'] ?? false) === true ? ' (estimate: the migrations table could not be read)' : '';
		if ($steps === []) {
			$output->writeln('No database changes' . $estimated . '.');

			return;
		}

		$output->writeln('Database changes' . $estimated . ':');
		$table = new Table($output);
		$table->setHeaders(['Step', 'Changes', 'Table', 'Rows', 'Advice']);
		foreach ($steps as $step) {
			$table->addRows(self::stepRows($step));
		}
		$table->render();
	}

	/**
	 * The table rows of one step: one per table it changes, or one row
	 * saying what it does without a table.
	 *
	 * @param array<array-key, mixed> $step
	 * @return list<list<string>>
	 */
	private static function stepRows(array $step): array {
		$name = (string)($step['name'] ?? '');
		$changes = self::stepChanges($step);
		$data = ($step['dataStep'] ?? false) === true ? 'Moves data; the time depends on the amount of data.' : '';
		$tables = self::arrays($step['tables'] ?? []);
		if ($tables === []) {
			return [[$name, $changes, '', '', $data]];
		}

		$rows = [];
		foreach ($tables as $table) {
			$rows[] = [$name, $changes, (string)($table['name'] ?? ''), self::rowCount($table), trim(self::sizeAdvice((string)($table['sizeClass'] ?? '')) . ' ' . $data)];
		}

		return $rows;
	}

	/**
	 * A table's row count, formatted, or empty when it was not counted.
	 *
	 * @param array<array-key, mixed> $table
	 */
	private static function rowCount(array $table): string {
		/** @var mixed $count */
		$count = $table['rows'] ?? null;

		return is_int($count) ? number_format($count) : '';
	}

	/**
	 * What a step declares, as short phrases; a step that describes nothing says so.
	 *
	 * @param array<array-key, mixed> $step
	 */
	private static function stepChanges(array $step): string {
		if (($step['describes'] ?? true) === false) {
			return 'This step does not describe what it changes.';
		}

		$phrases = [];
		foreach (self::arrays($step['actions'] ?? []) as $action) {
			$attribute = (string)($action['attribute'] ?? '');
			$phrases[] = self::ACTION_PHRASES[$attribute] ?? $attribute;
		}

		return implode(', ', array_values(array_unique($phrases)));
	}

	/**
	 * The array entries of a list-shaped value.
	 *
	 * @return list<array<array-key, mixed>>
	 */
	private static function arrays(mixed $value): array {
		$out = [];
		/** @var mixed $entry */
		foreach (is_array($value) ? $value : [] as $entry) {
			if (is_array($entry)) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/**
	 * The string entries of a list-shaped value.
	 *
	 * @return list<string>
	 */
	private static function strings(mixed $value): array {
		$out = [];
		/** @var mixed $entry */
		foreach (is_array($value) ? $value : [] as $entry) {
			if (is_string($entry)) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/** The advice for a size class; never a duration in seconds. */
	private static function sizeAdvice(string $sizeClass): string {
		return match ($sizeClass) {
			'new' => 'New table; fast.',
			'small' => 'Takes seconds.',
			'large' => 'May take minutes.',
			'very_large' => 'May take long; plan a maintenance window.',
			default => 'Size unknown.',
		};
	}
}
