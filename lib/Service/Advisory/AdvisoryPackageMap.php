<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Advisory;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves the package name in a Nextcloud security advisory to something this
 * instance can act on: an installed app id, the server itself, or nothing.
 *
 * WHY THIS IS NOT A ONE-LINER. The published feed names packages the way a
 * human would, not the way the instance does. Measured over the 27 distinct
 * packages in the live feed (2026-08-21):
 *
 *   Talk                  -> spreed              (id bears no resemblance)
 *   Team Folders          -> groupfolders        (the app was renamed)
 *   User OIDC             -> user_oidc
 *   user_oidc             -> user_oidc           (the SAME app, both spellings
 *                                                 appear in the same feed)
 *   Twofactor WebAuthn    -> twofactor_webauthn
 *   End-to-End Encryption -> end_to_end_encryption
 *
 * Normalising to lowercase alphanumerics collapses all of those, and matching
 * against BOTH the app id and the app's display name is what makes `Talk` find
 * `spreed` — the id never matches, the name does.
 *
 * INSTALLED APPS ARE INDEXED AS WELL AS THE STORE CATALOGUE, and that is not
 * redundant: apps bundled with the server are absent from the App Store
 * catalogue entirely. Measured, a catalogue-only index resolved 19 of 27
 * packages and missed `Photos` and `Flow` for exactly that reason.
 *
 * A name that resolves to nothing is DROPPED AND COUNTED, never guessed at. A
 * wrong match here either raises a false alarm on an app that is fine or,
 * worse, attaches a real advisory to the wrong app and leaves the affected one
 * looking clean.
 *
 * @psalm-api
 */
class AdvisoryPackageMap {
	/**
	 * Packages that describe the server rather than an app. These correlate
	 * against the server version, not an app version.
	 *
	 * @var list<string>
	 */
	private const SERVER_PACKAGES = ['server', 'enterpriseserver'];

	/**
	 * Packages this instance cannot act on: the desktop and mobile clients.
	 * They appear in the same feed, and surfacing them in an app list would be
	 * noise an administrator cannot resolve from here.
	 *
	 * @var list<string>
	 */
	private const CLIENT_PACKAGES = ['desktop', 'desktopclient', 'androidfiles', 'filesios', 'iosfiles', 'androidnextcloud'];

	/** Sentinel returned for advisories that describe the server itself. */
	public const SERVER = ':server';

	/** @var array<string, string>|null normalised name => app id */
	private ?array $index = null;

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Resolves a package name to an installed app id, {@see self::SERVER}, or
	 * null when the instance has nothing to correlate it against.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function resolve(string $packageName): ?string {
		$key = $this->normalise($packageName);
		if ($key === '') {
			return null;
		}

		if (in_array($key, self::SERVER_PACKAGES, true)) {
			return self::SERVER;
		}

		// Checked BEFORE the index, because an instance that happens to have an
		// app whose name normalises to `desktop` should still not be told about
		// the desktop client's advisories.
		if (in_array($key, self::CLIENT_PACKAGES, true)) {
			return null;
		}

		return $this->index()[$key] ?? null;
	}

	/**
	 * Builds the lookup once per request: every enabled app indexed under both
	 * its id and its display name.
	 *
	 * @return array<string, string>
	 */
	private function index(): array {
		if ($this->index !== null) {
			return $this->index;
		}

		$index = [];
		foreach ($this->appManager->getEnabledApps() as $appId) {
			$index[$this->normalise($appId)] = $appId;

			foreach ($this->appDisplayNames($appId) as $name) {
				$key = $this->normalise($name);
				// An id match is more trustworthy than a name match, so never
				// let a name overwrite one.
				if ($key !== '' && !isset($index[$key])) {
					$index[$key] = $appId;
				}
			}
		}

		$this->index = $index;

		return $index;
	}

	/**
	 * The display names in an enabled app's info.xml; none when it cannot be
	 * read.
	 *
	 * @return list<string>
	 */
	private function appDisplayNames(string $appId): array {
		try {
			$info = $this->appManager->getAppInfo($appId);
		} catch (\Throwable $error) {
			$this->logger->debug('AdvisoryPackageMap: could not read app info', [
				'app' => $appId,
				'message' => $error->getMessage(),
			]);

			return [];
		}

		return is_array($info) ? $this->displayNames($info) : [];
	}

	/**
	 * An app's `name` may be a plain string or a per-language map; both shapes
	 * occur in real info.xml files.
	 *
	 * @param array<array-key, mixed> $info
	 * @return list<string>
	 */
	private function displayNames(array $info): array {
		/** @var mixed $name */
		$name = $info['name'] ?? null;
		if (is_string($name)) {
			return [$name];
		}
		if (!is_array($name)) {
			return [];
		}

		$names = [];
		/** @var mixed $value */
		foreach ($name as $value) {
			if (is_string($value)) {
				$names[] = $value;
			}
		}

		return $names;
	}

	/**
	 * Lowercase alphanumerics only. This is what makes `User OIDC`,
	 * `user_oidc` and `USER-OIDC` the same key.
	 */
	private function normalise(string $value): string {
		return (string)preg_replace('/[^a-z0-9]/', '', strtolower($value));
	}
}
