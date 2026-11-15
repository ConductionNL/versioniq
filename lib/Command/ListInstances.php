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

use OCA\Versioniq\Service\Instance\InstanceConnection;
use OCA\Versioniq\Service\Instance\InstanceConnectionStore;
use OCA\Versioniq\Service\Instance\ManifestBuilder;
use OCA\Versioniq\Service\Instance\RemoteInstanceReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `occ versioniq:instances`: per app, the installed version on this instance
 * and on every connection, from the cached manifests or, with `--refresh`,
 * read first (install-multi-instance-promotion design D8).
 */
class ListInstances extends Command {
	public function __construct(
		private InstanceConnectionStore $store,
		private RemoteInstanceReader $reader,
		private ManifestBuilder $manifest,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/install-multi-instance-promotion/specs/cli-commands/spec.md#requirement-compare-instances-and-promote-versions-from-the-cli
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:instances')
			->setDescription('Compare the app versions of this instance and its connected instances')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the versions and each manifest\'s read time as JSON.')
			->addOption('refresh', null, InputOption::VALUE_NONE, 'Read every connection before printing.');
	}

	/**
	 * @spec openspec/changes/install-multi-instance-promotion/specs/cli-commands/spec.md#requirement-compare-instances-and-promote-versions-from-the-cli
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$local = $this->manifest->build();
		$columns = [['id' => 'self', 'name' => $local['instanceName'], 'stage' => $local['stage'], 'fetchedAt' => null, 'error' => null, 'apps' => self::versions($local['apps'])]];
		foreach ($this->store->all() as $connection) {
			$columns[] = $this->column($connection, (bool)$input->getOption('refresh'));
		}

		if ((bool)$input->getOption('json')) {
			$output->writeln((string)json_encode(['instances' => $columns], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return 0;
		}

		$this->renderTable($output, $columns);

		return 0;
	}

	/**
	 * @return array{id: string, name: string, stage: string, fetchedAt: ?string, error: ?string, apps: array<string, ?string>}
	 */
	private function column(InstanceConnection $connection, bool $refresh): array {
		try {
			$read = $refresh ? $this->reader->fetch($connection) : $this->reader->cached($connection);
		} catch (Throwable) {
			$read = $this->reader->cached($connection);
			$read['error'] = RemoteInstanceReader::ERROR_UNREACHABLE;
		}

		/** @var mixed $apps */
		$apps = $read['manifest']['apps'] ?? [];

		return [
			'id' => $connection->id,
			'name' => $connection->name,
			'stage' => $connection->stage,
			'fetchedAt' => $read['fetchedAt'],
			'error' => $read['error'],
			'apps' => self::versions(is_array($apps) ? $apps : []),
		];
	}

	/**
	 * App id => installed version, from a manifest's app list.
	 *
	 * @param array<array-key, mixed> $apps
	 * @return array<string, ?string>
	 */
	private static function versions(array $apps): array {
		$versions = [];
		/** @var mixed $app */
		foreach ($apps as $app) {
			if (!is_array($app)) {
				continue;
			}
			/** @var mixed $appId */
			$appId = $app['appId'] ?? null;
			/** @var mixed $version */
			$version = $app['installedVersion'] ?? null;
			if (is_string($appId)) {
				$versions[$appId] = is_string($version) && $version !== '' ? $version : null;
			}
		}

		return $versions;
	}

	/**
	 * @param list<array{id: string, name: string, stage: string, fetchedAt: ?string, error: ?string, apps: array<string, ?string>}> $columns
	 */
	private function renderTable(OutputInterface $output, array $columns): void {
		$appIds = [];
		foreach ($columns as $column) {
			$appIds += array_fill_keys(array_keys($column['apps']), true);
		}
		ksort($appIds);

		$rows = [];
		foreach (array_keys($appIds) as $appId) {
			$cells = array_map(static fn (array $column): string => $column['apps'][$appId] ?? 'Not installed', $columns);
			$rows[] = array_merge([$appId], $cells, [count(array_unique($cells)) > 1 ? 'Differs' : '']);
		}

		$header = array_map(static fn (array $column): string => sprintf('%s (%s)', $column['name'], $column['stage']), $columns);
		(new Table($output))->setHeaders(array_merge(['App'], $header, ['']))->setRows($rows)->render();

		foreach ($columns as $column) {
			if ($column['error'] !== null) {
				$output->writeln(sprintf('%s: %s Last read: %s.', $column['name'], $column['error'], $column['fetchedAt'] ?? 'never'));
			}
		}
	}
}
