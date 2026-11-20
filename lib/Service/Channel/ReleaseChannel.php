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

/**
 * The one rule for which versions an app's release channel admits: what a
 * pre-release is (design D1), what each channel admits (D2) and which
 * versions lie on a long-term support line (D4). Pure: no storage, no I/O.
 *
 * @psalm-api
 */
final class ReleaseChannel {
	public const SERVER = 'server';
	public const STABLE = 'stable';
	public const BETA = 'beta';
	public const LTS = 'lts';

	/** Every channel an app or the instance default can be set to. */
	public const ALL = [self::SERVER, self::STABLE, self::BETA, self::LTS];

	/** Server update channels that admit no pre-release; the rule safe mode uses. */
	private const STRICT_SERVER_CHANNELS = ['stable', 'production', 'enterprise'];

	/** A version number with a suffix after its numeric core, as `safeMode.ts` reads it. */
	private const SUFFIX_PATTERN = '/^v?\d+(\.\d+)*-.+/';

	/** A long-term support line: `major` or `major.minor`. */
	private const LINE_PATTERN = '/^\d+(\.\d+)?$/';

	/**
	 * Whether a channel name is one of the four.
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public static function isKnown(string $channel): bool {
		return in_array($channel, self::ALL, true);
	}

	/**
	 * Whether a value is a valid long-term support line.
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-long-term-support-channel-offers-only-marked-release-lines
	 */
	public static function isLine(string $line): bool {
		return preg_match(self::LINE_PATTERN, $line) === 1;
	}

	/**
	 * Whether a version entry is a pre-release: a suffixed number, or a source
	 * that marks it (a forge's `prerelease`, the App Store's `isNightly`),
	 * copied onto the entry as `sourcePreRelease`.
	 *
	 * @param array<array-key, mixed> $entry
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-every-listed-version-says-whether-it-is-a-pre-release
	 */
	public static function isPreRelease(array $entry): bool {
		if (($entry['sourcePreRelease'] ?? false) === true) {
			return true;
		}
		return preg_match(self::SUFFIX_PATTERN, self::versionOf($entry)) === 1;
	}

	/**
	 * Whether a channel admits a version entry.
	 *
	 * @param array<array-key, mixed> $entry
	 * @param list<string> $lines The app's long-term support lines, marked and publisher-marked.
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public static function admits(string $channel, array $entry, string $serverChannel, array $lines): bool {
		$preRelease = self::isPreRelease($entry);

		return match ($channel) {
			self::STABLE => !$preRelease,
			self::BETA => true,
			self::LTS => !$preRelease && self::onLine(self::versionOf($entry), $lines),
			default => !$preRelease || !self::serverRefusesPreReleases($serverChannel),
		};
	}

	/**
	 * Whether a version lies on one of the lines: its numeric core is the
	 * line, or starts with the line and a dot.
	 *
	 * @param list<string> $lines
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-long-term-support-channel-offers-only-marked-release-lines
	 */
	public static function onLine(string $version, array $lines): bool {
		$core = self::core($version);
		foreach ($lines as $line) {
			if ($core === $line || str_starts_with($core, $line . '.')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The lines a publisher marked itself: the `major.minor` of every entry
	 * a forge release names LTS (`sourceLts`), in first-seen order.
	 *
	 * @param list<array<array-key, mixed>> $entries
	 * @return list<string>
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-long-term-support-channel-offers-only-marked-release-lines
	 */
	public static function publisherLines(array $entries): array {
		$lines = [];
		foreach ($entries as $entry) {
			if (($entry['sourceLts'] ?? false) !== true) {
				continue;
			}
			$line = implode('.', array_slice(explode('.', self::core(self::versionOf($entry))), 0, 2));
			if (self::isLine($line) && !in_array($line, $lines, true)) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Whether a server update channel admits no pre-release. Every other
	 * channel, an unknown one included, admits everything, as safe mode did.
	 */
	private static function serverRefusesPreReleases(string $serverChannel): bool {
		return in_array(strtolower(trim($serverChannel)), self::STRICT_SERVER_CHANNELS, true);
	}

	/**
	 * @param array<array-key, mixed> $entry
	 */
	private static function versionOf(array $entry): string {
		return isset($entry['version']) && is_string($entry['version']) ? $entry['version'] : '';
	}

	/** A version's numeric core: no leading `v`, nothing after a `-` or `+`. */
	private static function core(string $version): string {
		$version = ltrim(trim($version), 'vV');

		return preg_split('/[-+]/', $version, 2)[0] ?? '';
	}
}
