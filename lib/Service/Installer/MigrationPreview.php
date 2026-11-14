<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

/**
 * The database changes an upgrade will make, built in a dry run from the
 * unpacked target: the migration steps Nextcloud has not run yet, what each
 * declares or names, the current size of each table it touches, and the
 * repair steps that follow. It reads files and counts rows; it never loads a
 * target class and never writes. See design.md D4 of the
 * `safety-upgrade-migration-preview` change.
 *
 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
 * @psalm-api
 *
 * @psalm-type PreviewTable = array{name: string, rows: int|null, sizeClass: string}
 * @psalm-type PreviewStep = array{name: string, actions: list<array{attribute: string, table: string, name: string, description: string}>, tables: list<PreviewTable>, dataStep: bool, describes: bool}
 * @psalm-type Preview = array{steps: list<PreviewStep>, repairSteps: list<string>, estimated: bool}
 */
class MigrationPreview {
	public function __construct(
		private MigrationDiffer $differ,
		private MigrationAttributeReader $reader,
		private TableSizeProbe $probe,
	) {
	}

	/**
	 * The preview of an upgrade to the target at `$targetAppPath`, or null
	 * when the target cannot be read.
	 *
	 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
	 * @return Preview|null
	 */
	public function build(string $appId, ?string $installedAppPath, ?string $targetAppPath): ?array {
		$pending = $this->differ->pending($appId, $targetAppPath, $installedAppPath);
		if ($pending === null || $targetAppPath === null) {
			return null;
		}

		$described = $this->describe($targetAppPath, $pending['steps']);
		$sizes = $this->sizes($described);

		$steps = [];
		foreach ($described as $name => $step) {
			$steps[] = [
				'name' => $name,
				'actions' => $step['actions'],
				'tables' => array_map(static fn (string $table): array => $sizes[$table], $step['tables']),
				'dataStep' => $step['dataStep'],
				'describes' => $step['describes'],
			];
		}

		return [
			'steps' => $steps,
			'repairSteps' => self::repairSteps($targetAppPath),
			'estimated' => $pending['estimated'],
		];
	}

	/**
	 * What each pending step declares; a step file that cannot be read
	 * describes nothing.
	 *
	 * @param list<string> $steps
	 * @return array<string, array{actions: list<array{attribute: string, table: string, name: string, description: string}>, tables: list<string>, dataStep: bool, describes: bool}>
	 */
	private function describe(string $targetAppPath, array $steps): array {
		$described = [];
		foreach ($steps as $step) {
			$file = rtrim($targetAppPath, '/') . '/lib/Migration/' . $step . '.php';
			$described[$step] = $this->reader->read($file)
				?? ['actions' => [], 'tables' => [], 'dataStep' => false, 'describes' => false];
		}

		return $described;
	}

	/**
	 * The size of every table the steps touch, counted once each and capped
	 * by the probe across the whole preview.
	 *
	 * @param array<string, array{actions: list<array{attribute: string, table: string, name: string, description: string}>, tables: list<string>, dataStep: bool, describes: bool}> $described
	 * @return array<string, PreviewTable>
	 */
	private function sizes(array $described): array {
		$tables = [];
		foreach ($described as $step) {
			array_push($tables, ...$step['tables']);
		}

		$sizes = [];
		foreach ($this->probe->probe(array_values(array_unique($tables))) as $row) {
			$sizes[$row['name']] = $row;
		}

		return $sizes;
	}

	/**
	 * The class names the target's `info.xml` lists under
	 * `repair-steps/post-migration`, in order; [] when it lists none or
	 * cannot be read.
	 *
	 * @return list<string>
	 */
	private static function repairSteps(string $targetAppPath): array {
		$file = rtrim($targetAppPath, '/') . '/appinfo/info.xml';
		if (!is_file($file) || !is_readable($file)) {
			return [];
		}

		$xml = @simplexml_load_string((string)file_get_contents($file), \SimpleXMLElement::class, LIBXML_NONET);
		if ($xml === false) {
			return [];
		}

		$nodes = $xml->xpath('/info/repair-steps/post-migration/step');
		$steps = [];
		foreach (is_array($nodes) ? $nodes : [] as $step) {
			$name = trim((string)$step);
			if ($name !== '') {
				$steps[] = $name;
			}
		}

		return $steps;
	}
}
