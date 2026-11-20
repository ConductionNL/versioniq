<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Source;

/**
 * Gives listed forge versions the server and PHP range their own
 * `appinfo/info.xml` declares, without downloading the release
 * (inventory-upgrade-readiness D2).
 *
 * A version in the artifact cache is left alone: its archive is read for the
 * server range already, and it wins. Any other version takes its stored
 * range; one never read before is read from the forge at its tag, at most
 * {@see MAX_READS} per listing (newest first), and stored, an unreadable
 * file as no range. A request that fails is not stored, so it is asked again.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
 * @spec openspec/changes/inventory-upgrade-readiness/specs/version-management/spec.md#requirement-versions-say-whether-they-run-on-this-servers-php
 * @psalm-api
 */
class ForgeRangeStamper {
	/** Forge reads one listing may make. */
	public const MAX_READS = 5;

	/** Per spec field: the verdict it gives, and the running version it is checked against. */
	private const VERDICTS = [
		'platformSpec' => ['serverCompatible', 'server'],
		'phpSpec' => ['phpCompatible', 'php'],
	];

	/** Forge reads the listing being stamped may still make. */
	private int $readsLeft = 0;

	public function __construct(
		private ForgeRangeStore $store,
		private ForgeReleaseSource $forge,
	) {
	}

	/**
	 * The versions with `platformSpec` and `phpSpec` filled where a range is
	 * known; a value already set stays. {@see withVerdicts()} then says what
	 * they mean for this server.
	 *
	 * @param list<array<string, mixed>> $versions The listing, newest first.
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
	 */
	public function stamp(array $versions, SourceBinding $binding): array {
		if ($binding->kind !== SourceBinding::KIND_GITHUB_RELEASE) {
			return $versions;
		}

		$this->readsLeft = self::MAX_READS;
		foreach ($versions as $index => $entry) {
			$ranges = $this->rangesFor($entry, $binding);
			if ($ranges !== null) {
				$versions[$index] = self::withSpecs($entry, $ranges);
			}
		}

		return $versions;
	}

	/**
	 * `serverCompatible` and `phpCompatible` for the versions that carry a
	 * spec but no verdict yet: null where the spec is null.
	 *
	 * @param list<array<string, mixed>>         $versions
	 * @param array{server: string, php: string} $running  This server's version and PHP version.
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/version-management/spec.md#requirement-versions-say-whether-they-run-on-this-servers-php
	 */
	public static function withVerdicts(array $versions, array $running): array {
		foreach ($versions as $index => $entry) {
			foreach (self::VERDICTS as $spec => [$verdict, $target]) {
				if (array_key_exists($spec, $entry) && !array_key_exists($verdict, $entry)) {
					$versions[$index][$verdict] = self::verdict($running[$target], $entry[$spec]);
				}
			}
		}

		return $versions;
	}

	/**
	 * The stored or freshly read ranges of one version, or null when it is
	 * cached, has no version, is past the read budget, or could not be read.
	 *
	 * @param array<string, mixed> $entry
	 */
	private function rangesFor(array $entry, SourceBinding $binding): ?InfoXmlRanges {
		$version = $entry['version'] ?? null;
		if (!is_string($version) || $version === '' || ($entry['cachedOffline'] ?? false) === true) {
			return null;
		}

		$release = ForgeRangeStore::release($binding, $version);
		$stored = $this->store->find($release);
		if ($stored !== null || $this->readsLeft <= 0) {
			return $stored;
		}

		$this->readsLeft--;

		return $this->read($binding, $version);
	}

	private function read(SourceBinding $binding, string $version): ?InfoXmlRanges {
		$infoXml = $this->forge->infoXmlAtTag($binding, $version);
		if ($infoXml === null) {
			return null;
		}

		$ranges = InfoXmlRanges::fromXml($infoXml);
		$this->store->save(ForgeRangeStore::release($binding, $version), $ranges);

		return $ranges;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function withSpecs(array $entry, InfoXmlRanges $ranges): array {
		$platform = $ranges->platformSpec();
		$php = $ranges->phpSpec();
		$entry['platformSpec'] ??= $platform === '' ? null : $platform;
		$entry['phpSpec'] ??= $php === '' ? null : $php;

		return $entry;
	}

	/** Whether a version runs on the running one, by its spec; null without a spec. */
	private static function verdict(string $running, mixed $spec): ?bool {
		return is_string($spec) && $spec !== '' ? AppStoreSource::satisfiesPlatformSpec($running, $spec) : null;
	}
}
