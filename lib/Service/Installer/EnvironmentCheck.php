<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

use OCA\Versioniq\AppInfo\Application;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Detects environment conditions that prevent a successful install.
 *
 * The authoritative, blocking check is whether the PARENT directory of an
 * app's folder is writable — `rename()` of the existing folder (the backup
 * step) needs write permission on the parent, not on the folder itself.
 * Dev-checkout heuristics (a `.git` directory, owner ≠ web-server uid) only
 * enrich the human warning and never block on their own.
 *
 * @spec openspec/specs/version-management/spec.md
 * @psalm-api
 */
class EnvironmentCheck {
	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	/**
	 * The app's translator. Each inspect() translates at most one string, and
	 * the factory keeps one instance per app and language, so this class holds
	 * no copy of its own.
	 */
	private function l10n(): IL10N {
		return $this->l10nFactory->get(Application::APP_ID);
	}

	/**
	 * Authoritative functional check used by the fail-fast guard: can the
	 * web-server user rename the app folder (i.e. is its parent writable)?
	 */
	public function isDestinationWritable(string $appPath): bool {
		return is_writable(dirname($appPath));
	}

	/**
	 * Inspects an installed app's folder and returns a card descriptor.
	 *
	 * @return array{manageable: bool, warning: ?string}
	 */
	public function inspect(string $appPath): array {
		$writable = $this->isDestinationWritable($appPath);

		if (!$writable) {
			return [
				'manageable' => false,
				'warning' => $this->l10n()->t('This app\'s folder is not writable by the web-server user, so version changes will fail. It is likely a bind-mounted dev checkout — fix the folder ownership/permissions to manage it here.'),
			];
		}

		// Writable: manageable. Advisory dev-checkout signals enrich the warning
		// but never flip `manageable` to false.
		$advisory = $this->devCheckoutWarning($appPath);

		return [
			'manageable' => true,
			'warning' => $advisory,
		];
	}

	/**
	 * Advisory-only warning for a writable folder that still looks like a dev
	 * checkout (contains `.git`, or is owned by a different uid than the
	 * web-server process). Returns null when no heuristic matches.
	 */
	private function devCheckoutWarning(string $appPath): ?string {
		if (!$this->looksLikeDevCheckout($appPath)) {
			return null;
		}

		return $this->l10n()->t('This app looks like a development checkout (e.g. a Git working copy or a bind mount owned by another user). Version changes here will overwrite your working copy.');
	}

	/**
	 * Whether either dev-checkout signal matches: a `.git` directory, or an
	 * owner other than the web-server process. Both signals are read.
	 */
	private function looksLikeDevCheckout(string $appPath): bool {
		$hasGitDir = is_dir($appPath . '/.git');
		$foreignOwner = $this->isOwnedByAnotherUser($appPath);

		return $hasGitDir || $foreignOwner;
	}

	/**
	 * Whether the folder's owner differs from the web-server process; false
	 * when posix is unavailable or the owner cannot be read.
	 */
	private function isOwnedByAnotherUser(string $appPath): bool {
		if (!function_exists('posix_getuid')) {
			return false;
		}
		$owner = @fileowner($appPath);

		return $owner !== false && $owner !== posix_getuid();
	}
}
