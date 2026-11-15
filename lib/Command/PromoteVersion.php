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

use OCA\Versioniq\Service\Instance\PromotionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:promote <connection> <appId>...`: promotes each app from a
 * connection in order, with the same checks as the page
 * (install-multi-instance-promotion design D8). Exits with the
 * `versioniq:install` code of the first failure, 10 when the waiting period is
 * not met and 11 when the connection is unknown or cannot be read.
 */
class PromoteVersion extends Command {
	public const EXIT_OK = 0;
	public const EXIT_WAITING_PERIOD = 10;
	public const EXIT_CONNECTION = 11;

	public function __construct(
		private PromotionService $promotions,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/install-multi-instance-promotion/specs/cli-commands/spec.md#requirement-compare-instances-and-promote-versions-from-the-cli
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:promote')
			->setDescription('Promote the versions an earlier stage runs to this instance')
			->addArgument('connection', InputArgument::REQUIRED, 'The connection id of the earlier stage.')
			->addArgument('appIds', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The apps to promote, in order.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check every app without installing anything.')
			->addOption('allow-downgrade', null, InputOption::VALUE_NONE, 'Acknowledge promotions that install an older version.')
			->addOption('override-min-days', null, InputOption::VALUE_NONE, 'Promote inside the waiting period; the override is audited.')
			->addOption('continue', null, InputOption::VALUE_NONE, 'Go on with the next app after a failure.');
	}

	/**
	 * @spec openspec/changes/install-multi-instance-promotion/specs/cli-commands/spec.md#requirement-compare-instances-and-promote-versions-from-the-cli
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$connection = (string)$input->getArgument('connection');
		/** @var list<string> $appIds */
		$appIds = (array)$input->getArgument('appIds');
		$exitCode = self::EXIT_OK;

		foreach ($appIds as $appId) {
			$result = $this->promotions->promote(
				$connection,
				$appId,
				(bool)$input->getOption('allow-downgrade'),
				(bool)$input->getOption('override-min-days'),
				null,
				(bool)$input->getOption('dry-run'),
			);
			$code = self::exitCodeFor($result['statusCode'], $result['payload']);
			$this->report($output, $appId, $code, $result['payload']);
			if ($code === self::EXIT_OK) {
				continue;
			}

			$exitCode = $exitCode === self::EXIT_OK ? $code : $exitCode;
			if (!(bool)$input->getOption('continue')) {
				break;
			}
		}

		return $exitCode;
	}

	/**
	 * The exit code of one promotion's outcome.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function exitCodeFor(int $statusCode, array $payload): int {
		return match ($payload['code'] ?? null) {
			PromotionService::REFUSED_WAITING_PERIOD => self::EXIT_WAITING_PERIOD,
			PromotionService::REFUSED_UNKNOWN_CONNECTION, PromotionService::REFUSED_UNREADABLE => self::EXIT_CONNECTION,
			default => InstallVersion::exitCodeFor($statusCode, $payload),
		};
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function report(OutputInterface $output, string $appId, int $code, array $payload): void {
		if ($code !== self::EXIT_OK) {
			/** @var mixed $message */
			$message = $payload['message'] ?? null;
			$message = is_string($message) ? $message : 'The promotion failed.';
			$error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
			$error->writeln(sprintf('<error>%s: %s</error>', $appId, $message));
			return;
		}

		/** @var mixed $plan */
		$plan = $payload['plan'] ?? null;
		if (($payload['dryRun'] ?? false) === true && is_array($plan)) {
			$output->writeln(sprintf('%s: would promote %s to %s.', $appId, (string)($plan['installedVersion'] ?? ''), (string)($plan['version'] ?? '')));
			return;
		}

		/** @var mixed $from */
		$from = $payload['promotedFrom'] ?? null;
		$name = is_array($from) ? (string)($from['name'] ?? '') : '';
		$output->writeln(sprintf('%s: promoted from %s; the previous version was %s.', $appId, $name, (string)($payload['previousVersion'] ?? '')));
	}
}
