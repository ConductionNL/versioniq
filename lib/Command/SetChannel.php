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

use InvalidArgumentException;
use OCA\Versioniq\Service\Channel\ChannelStore;
use OCA\Versioniq\Service\Channel\ReleaseChannel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ versioniq:channel [<appId>] [<channel>] [--lts-lines=2.3,2.4]` — reads
 * or sets the release channels. Without arguments it prints the default and
 * every per-app channel; with an app it prints that app's channel and lines;
 * with a channel it sets it (`default` for an app clears its own channel).
 * An unknown channel or line exits non-zero without a change.
 *
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-channel-is-visible-and-settable-from-the-cli
 * @psalm-api
 */
class SetChannel extends Command {
	/** The channel argument that hands an app back to the instance default. */
	private const FOLLOW_DEFAULT = 'default';

	public function __construct(
		private ChannelStore $store,
	) {
		parent::__construct();
	}

	/**
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-channel-is-visible-and-settable-from-the-cli
	 */
	protected function configure(): void {
		$this
			->setName('versioniq:channel')
			->setDescription('Show or set the release channel of the instance or of one app.')
			->addArgument('appId', InputArgument::OPTIONAL, 'The app; leave it out to show every channel, or pass "default" to set the instance default.')
			->addArgument('channel', InputArgument::OPTIONAL, 'The channel to set: ' . implode(', ', ReleaseChannel::ALL) . '; "default" makes an app follow the instance default.')
			->addOption('lts-lines', null, InputOption::VALUE_REQUIRED, 'The long-term support lines of the app, comma-separated (e.g. 2.3,2.4); empty clears them.');
	}

	/**
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-channel-is-visible-and-settable-from-the-cli
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = self::text($input->getArgument('appId'));
		/** @var mixed $lines */
		$lines = $input->getOption('lts-lines');

		try {
			$this->apply($appId, self::text($input->getArgument('channel')), is_string($lines) ? $lines : null);
		} catch (InvalidArgumentException $error) {
			$output->writeln(sprintf('<error>%s</error>', $error->getMessage()));

			return 1;
		}

		$this->show($output, $appId);

		return 0;
	}

	/**
	 * Sets what the arguments ask for; nothing for a read.
	 *
	 * @throws InvalidArgumentException For an unknown channel or line, or lines without an app.
	 */
	private function apply(string $appId, string $channel, ?string $lines): void {
		$lineList = self::lineList($appId, $lines);
		if ($channel !== '') {
			self::assertChannel($appId, $channel);
			$this->setChannel($appId, $channel);
		}
		if ($lineList !== null) {
			$this->store->setLtsLines($appId, $lineList);
		}
	}

	/**
	 * The lines asked for, checked before anything is set; null when none were.
	 *
	 * @return list<string>|null
	 * @throws InvalidArgumentException For lines without an app, or a line that is not `major` or `major.minor`.
	 */
	private static function lineList(string $appId, ?string $lines): ?array {
		if ($lines === null) {
			return null;
		}
		if ($appId === '' || $appId === self::FOLLOW_DEFAULT) {
			throw new InvalidArgumentException('--lts-lines needs the app whose lines they are.');
		}
		$list = $lines === '' ? [] : array_map('trim', explode(',', $lines));
		foreach ($list as $line) {
			if (!ReleaseChannel::isLine($line)) {
				throw new InvalidArgumentException('A long-term support line is a major or major.minor version.');
			}
		}

		return $list;
	}

	/**
	 * @throws InvalidArgumentException For an unknown channel, or "default" as the instance default.
	 */
	private static function assertChannel(string $appId, string $channel): void {
		$followsDefault = $channel === self::FOLLOW_DEFAULT && $appId !== '' && $appId !== self::FOLLOW_DEFAULT;
		if (!$followsDefault && !ReleaseChannel::isKnown($channel)) {
			throw new InvalidArgumentException('Unknown release channel: ' . $channel);
		}
	}

	/**
	 * @throws InvalidArgumentException For an unknown channel.
	 */
	private function setChannel(string $appId, string $channel): void {
		if ($appId === '' || $appId === self::FOLLOW_DEFAULT) {
			$this->store->setDefault($channel);
		} elseif ($channel === self::FOLLOW_DEFAULT) {
			$this->store->clearAppChannel($appId);
		} else {
			$this->store->setAppChannel($appId, $channel);
		}
	}

	/**
	 * Prints every channel, or one app's.
	 */
	private function show(OutputInterface $output, string $appId): void {
		if ($appId === '' || $appId === self::FOLLOW_DEFAULT) {
			$output->writeln(sprintf('Default: %s', $this->store->defaultChannel()));
			foreach ($this->store->appChannels() as $app => $own) {
				$output->writeln(sprintf('%s: %s', $app, $own));
			}

			return;
		}

		$own = $this->store->appChannel($appId);
		$output->writeln(sprintf('%s: %s', $appId, $own ?? $this->store->defaultChannel() . ' (the default)'));
		$lines = $this->store->ltsLines($appId);
		if ($lines !== []) {
			$output->writeln(sprintf('Long-term support lines: %s', implode(', ', $lines)));
		}
	}

	/**
	 * An argument as trimmed text; '' when it was left out.
	 */
	private static function text(mixed $value): string {
		return is_string($value) ? trim($value) : '';
	}
}
