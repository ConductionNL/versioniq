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
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Throwable;

/**
 * Classifies an install failure into a machine-readable category and maps that
 * category to an actionable, translatable hint and an appropriate HTTP status.
 *
 * The category is derived from (a) lightweight inspection of the exception
 * message and (b) the last installer breadcrumb stage, without retrofitting a
 * typed-exception hierarchy onto the installers. See
 * `openspec/changes/improve-install-failure-diagnostics/design.md` (D1).
 *
 * @spec openspec/specs/version-management/spec.md
 * @psalm-api
 */
class FailureClassifier {
	public const CATEGORY_PREFLIGHT_PERMISSION = 'preflight_permission';
	public const CATEGORY_DOWNLOAD = 'download';
	public const CATEGORY_CHECKSUM_MISMATCH = 'checksum_mismatch';
	/**
	 * A recorded (trust-on-first-use) SHA-256 mismatch — distinct from
	 * {@see CATEGORY_CHECKSUM_MISMATCH} (the source-published sibling
	 * checksum): this is a history check the source cannot rewrite. See
	 * "Recorded SHA-256 enforced on reinstall".
	 */
	public const CATEGORY_SHA_MISMATCH = 'sha_mismatch';
	public const CATEGORY_EXTRACT = 'extract';
	public const CATEGORY_APPID_MISMATCH = 'appid_mismatch';
	public const CATEGORY_VERSION_MISMATCH = 'version_mismatch';
	public const CATEGORY_INCOMPATIBLE = 'incompatible';
	public const CATEGORY_FILESYSTEM = 'filesystem';
	public const CATEGORY_FINALIZE = 'finalize';
	/**
	 * A downgrade requested without `allowDowngrade: true` — see "Server-side
	 * downgrade guard". Distinct from every other category: nothing was
	 * attempted (no download, no filesystem change), the request is simply
	 * refused pending acknowledgement.
	 */
	public const CATEGORY_DOWNGRADE_GUARD = 'downgrade_guard';
	public const CATEGORY_UNKNOWN = 'unknown';

	/**
	 * Tri-state outcome of the post-swap restore attempt, used to pick an honest
	 * finalize-failure hint. A fresh install (no prior version) must not be told
	 * that "previous files could not be restored" — there were none.
	 */
	public const RESTORE_CLEAN = 'clean';
	public const RESTORE_FAILED = 'failed';
	public const RESTORE_NONE = 'no_prior_version';

	/**
	 * Canonical breadcrumb stage names emitted by the installers. Documented
	 * here so the orchestrator and tests share one vocabulary.
	 */
	public const STAGE_REQUESTED = 'requested-install';
	public const STAGE_DOWNLOADED = 'downloaded';
	public const STAGE_CHECKSUM = 'checksum';
	public const STAGE_EXTRACTED = 'archive-extracted';
	public const STAGE_INFO_VALIDATED = 'info-validated';
	public const STAGE_FILESYSTEM_UPDATED = 'filesystem-updated';
	public const STAGE_FINALIZE = 'finalize';

	/**
	 * Message phrases per category, checked in this order; the first phrase
	 * found in the (lower-cased) message decides.
	 *
	 * Filesystem-operation failures (backup rename, destination mkdir) happen
	 * *after* the pre-flight writability guard already passed, so they come
	 * after the permission phrases and are not reported as a preflight
	 * permission problem: that would tell the admin to fix permissions the
	 * guard already confirmed are correct.
	 */
	private const MESSAGE_CATEGORIES = [
		[self::CATEGORY_INCOMPATIBLE, ['not compatible']],
		[self::CATEGORY_APPID_MISMATCH, ['app id', 'appid']],
		[self::CATEGORY_VERSION_MISMATCH, ['version does not match', 'declares version', 'version is missing']],
		[self::CATEGORY_CHECKSUM_MISMATCH, ['sha-256', 'checksum']],
		[self::CATEGORY_DOWNLOAD, ['download']],
		[self::CATEGORY_EXTRACT, ['extract']],
		[self::CATEGORY_PREFLIGHT_PERMISSION, ['permission', 'not writable', 'cannot write']],
		[self::CATEGORY_FILESYSTEM, ['backup existing app folder', 'create app destination folder', 'resolve app install folder']],
	];

	/** The category for an opaque message, by the last stage reached. */
	private const STAGE_CATEGORIES = [
		self::STAGE_REQUESTED => self::CATEGORY_DOWNLOAD,
		self::STAGE_DOWNLOADED => self::CATEGORY_CHECKSUM_MISMATCH,
		self::STAGE_CHECKSUM => self::CATEGORY_EXTRACT,
		self::STAGE_FILESYSTEM_UPDATED => self::CATEGORY_FINALIZE,
		self::STAGE_FINALIZE => self::CATEGORY_FINALIZE,
	];

	private ?IL10N $l = null;

	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	private function l10n(): IL10N {
		if ($this->l === null) {
			$this->l = $this->l10nFactory->get(Application::APP_ID);
		}

		return $this->l;
	}

	/**
	 * Maps a category to its HTTP status. Classified failures never collapse to
	 * a blanket 500; 500 is reserved for `extract`/`finalize`/`unknown`.
	 */
	public function httpStatusFor(string $category): int {
		return match ($category) {
			self::CATEGORY_PREFLIGHT_PERMISSION,
			self::CATEGORY_DOWNGRADE_GUARD => Http::STATUS_CONFLICT,
			self::CATEGORY_INCOMPATIBLE,
			self::CATEGORY_VERSION_MISMATCH,
			self::CATEGORY_APPID_MISMATCH,
			self::CATEGORY_CHECKSUM_MISMATCH,
			self::CATEGORY_SHA_MISMATCH => Http::STATUS_UNPROCESSABLE_ENTITY,
			self::CATEGORY_DOWNLOAD => Http::STATUS_BAD_GATEWAY,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};
	}

	/**
	 * Derives a category from the exception message and last breadcrumb stage.
	 *
	 * Message inspection is primary because the installers throw consistent,
	 * descriptive messages; the breadcrumb stage is a secondary tie-breaker.
	 */
	public function categoryFor(Throwable $error, ?string $lastStage = null): string {
		$message = strtolower($error->getMessage());
		foreach (self::MESSAGE_CATEGORIES as [$category, $phrases]) {
			foreach ($phrases as $phrase) {
				if (str_contains($message, $phrase)) {
					return $category;
				}
			}
		}

		// Fall back to the last reached stage when the message is opaque.
		return self::STAGE_CATEGORIES[$lastStage ?? ''] ?? self::CATEGORY_UNKNOWN;
	}

	/**
	 * An actionable, translatable remediation hint for a category.
	 */
	public function hintFor(string $category): string {
		$l = $this->l10n();

		return match ($category) {
			self::CATEGORY_PREFLIGHT_PERMISSION => $l->t('The app folder is not writable by the web-server user. If this is a bind-mounted dev checkout, fix the folder ownership/permissions (or install the app into a writable apps directory).'),
			self::CATEGORY_DOWNLOAD => $l->t('The release could not be downloaded from its source. Check connectivity to the source and that the release asset still exists.'),
			self::CATEGORY_CHECKSUM_MISMATCH => $l->t('The downloaded archive failed its integrity check. The release may be corrupted or tampered with; do not install it.'),
			self::CATEGORY_SHA_MISMATCH => $l->t('The downloaded artifact does not match the SHA-256 recorded the first time this version was installed. The upstream release may have been rewritten. Only proceed if you are certain the new artifact is legitimate, then explicitly accept the new checksum to install it.'),
			self::CATEGORY_EXTRACT => $l->t('The release archive could not be extracted. The downloaded file may be incomplete or not a valid app archive.'),
			self::CATEGORY_APPID_MISMATCH => $l->t('The downloaded archive is for a different app than requested. Verify the source binding points at the correct repository.'),
			self::CATEGORY_VERSION_MISMATCH => $l->t('The downloaded archive declares a different version than requested. The source metadata and asset may be out of sync.'),
			self::CATEGORY_INCOMPATIBLE => $l->t('This version is not compatible with the current Nextcloud server version. Choose a compatible release.'),
			self::CATEGORY_FILESYSTEM => $l->t('A filesystem operation (moving or creating the app folder) failed even though the pre-flight writability check passed. This can happen on a concurrent install, a momentarily busy filesystem, or a stale ".appversion-backup" folder left behind by a previous failure. Remove any leftover "*.appversion-backup" folder next to the app directory and retry.'),
			self::CATEGORY_FINALIZE => $l->t('The app files were updated but a migration or repair step failed. The previous files were restored, but database migrations may have already partially applied and cannot be rolled back automatically — verify the app and its data manually.'),
			default => $l->t('An unexpected error occurred during installation. Check the server log for details.'),
		};
	}

	/**
	 * Hint for a finalize-phase failure. Honest about the database: even when
	 * the files were restored, migrations may have partially applied. The
	 * restore state is tri-state so a fresh install (no prior version) is not
	 * told that previous files could not be restored — there were none.
	 *
	 * @param self::RESTORE_* $restoreState
	 */
	public function finalizeHint(string $restoreState): string {
		return match ($restoreState) {
			self::RESTORE_CLEAN => $this->hintFor(self::CATEGORY_FINALIZE),
			self::RESTORE_NONE => $this->l10n()->t('This was a fresh install (no previous version existed). A migration or repair step failed, so the new app files are present but the app is not fully installed. Remove the app folder and any tables it created, then retry.'),
			default => $this->l10n()->t('The previous app files could not be reliably restored — the installation is in an indeterminate state and requires manual intervention. Check the app folder and its database tables.'),
		};
	}

	/**
	 * Hint for a cleanly reverted pre-finalize failure: nothing was applied.
	 */
	public function revertedHint(): string {
		return $this->l10n()->t('No changes were applied; the previously installed version remains intact.');
	}

	/**
	 * Hint for the downgrade guard, naming both versions concretely; see
	 * "Server-side downgrade guard".
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 */
	public function downgradeGuardHint(string $installedVersion, string $targetVersion): string {
		// The versions MUST go through `t()`'s parameter array: L10NString
		// vsprintf()s the translated text against exactly these parameters, so
		// a placeholder-bearing string passed without them throws a ValueError
		// ("The arguments array must contain 2 items, 0 given") the moment the
		// string is cast — i.e. on every downgrade refusal.
		return (string)$this->l10n()->t(
			'%1$s is installed; %2$s is older. Downgrading cannot undo database migrations already applied by %1$s — pass allowDowngrade to proceed anyway.',
			[$installedVersion, $targetVersion],
		);
	}

	/**
	 * A short human description for a category, used when there is no underlying
	 * exception message to surface (e.g. the pre-flight guard).
	 */
	public function messageFor(string $category): string {
		$l = $this->l10n();

		return match ($category) {
			self::CATEGORY_PREFLIGHT_PERMISSION => $l->t('The app folder is not writable by the web-server user.'),
			self::CATEGORY_DOWNGRADE_GUARD => $l->t('The requested version is older than the installed version. Pass allowDowngrade to proceed.'),
			default => $l->t('Installation failed.'),
		};
	}

	/**
	 * Builds the structured failure descriptor attached to every failure payload.
	 *
	 * @return array{category: string, hint: string, statusCode: int}
	 */
	public function classify(Throwable $error, ?string $lastStage = null, ?string $forcedCategory = null): array {
		$category = $forcedCategory ?? $this->categoryFor($error, $lastStage);

		return [
			'category' => $category,
			'hint' => $this->hintFor($category),
			'statusCode' => $this->httpStatusFor($category),
		];
	}
}
