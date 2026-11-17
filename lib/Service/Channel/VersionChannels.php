<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Channel;

use OCA\Versioniq\Service\Advisory\ServerVersionProvider;

/**
 * Stamps an app's version list with what its release channel admits:
 * `preRelease`, `lts` and `onChannel` on every entry, and the effective
 * channel for the envelope. The list itself is not filtered (design D3): the
 * picker reveals the rest on request and the CLI lists every version.
 *
 * @psalm-api
 */
class VersionChannels {
	public function __construct(
		private ChannelStore $store,
		private ServerVersionProvider $serverVersion,
	) {
	}

	/**
	 * The app's effective channel and its stamped versions, in the same order.
	 *
	 * @param list<array<string, mixed>> $versions
	 * @return array{channel: string, versions: list<array<string, mixed>>}
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-every-listed-version-says-whether-it-is-a-pre-release
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public function stamp(string $appId, array $versions): array {
		$channel = $this->store->effectiveChannel($appId);
		$lines = $this->store->ltsLines($appId);
		foreach (ReleaseChannel::publisherLines($versions) as $line) {
			if (!in_array($line, $lines, true)) {
				$lines[] = $line;
			}
		}
		$serverChannel = $this->serverChannel();

		$stamped = [];
		foreach ($versions as $entry) {
			$entry['preRelease'] = ReleaseChannel::isPreRelease($entry);
			$entry['lts'] = ReleaseChannel::onLine(isset($entry['version']) && is_string($entry['version']) ? $entry['version'] : '', $lines);
			$entry['onChannel'] = ReleaseChannel::admits($channel, $entry, $serverChannel, $lines);
			$stamped[] = $entry;
		}

		return ['channel' => $channel, 'versions' => $stamped];
	}

	/** The server's update channel, or '' when it cannot be read (which admits everything). */
	private function serverChannel(): string {
		try {
			return $this->serverVersion->channel();
		} catch (\Throwable) {
			return '';
		}
	}
}
