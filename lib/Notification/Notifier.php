<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Notification;

use OCA\Versioniq\AppInfo\Application;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders Versioniq notifications (the `pinned_to_vulnerable` advisory
 * notice, the `pat_expiring` / `pat_expired` token-expiry notices, the
 * `pin_drift` version-pinning notice, and the `auto_update_success` /
 * `auto_update_failure` auto-update outcome notices) into localized
 * subject/message text for the notifications app. Read-only presentation —
 * it never changes a version, a token, or a pin.
 *
 * @psalm-api
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Versioniq';
	}

	/**
	 * Renders the localized subject/message for a notification; see
	 * "PAT expiry warnings" for the `pat_expiring` / `pat_expired` subjects,
	 * "Drift response — notify and offer re-pin" for `pin_drift`, and
	 * "Every auto-update outcome is reported" for `auto_update_success` /
	 * `auto_update_failure`, and the weekly `advisory_digest`.
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

		$render = match ($notification->getSubject()) {
			'pinned_to_vulnerable' => $this->renderPinnedToVulnerable(...),
			'pat_expiring' => $this->renderPatExpiring(...),
			'pin_drift' => $this->renderPinDrift(...),
			'pat_expired' => $this->renderPatExpired(...),
			'auto_update_success' => $this->renderAutoUpdateSuccess(...),
			'auto_update_failure' => $this->renderAutoUpdateFailure(...),
			// Sent weekly by AdvisoryDigestNotifier. Without this branch the
			// digest was stored but skipped by the bell every time (#428).
			'advisory_digest' => $this->renderAdvisoryDigest(...),
			'new_release' => $this->renderNewRelease(...),
			default => throw new UnknownNotificationException(),
		};
		$render($notification, $l);

		return $notification;
	}

	/**
	 * "New version of {app}: {version}", and a note when it does not run here.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 */
	private function renderNewRelease(INotification $notification, IL10N $l): void {
		$app = $this->stringParameter($notification, 'app');
		$version = $this->stringParameter($notification, 'version');

		$notification->setParsedSubject($l->t('New version of %1$s: %2$s', [$app, $version]));
		if ($this->stringParameter($notification, 'compatible') !== '1') {
			$notification->setParsedMessage($l->t('It does not run on this server version.'));
		}
	}

	private function renderPinnedToVulnerable(INotification $notification, IL10N $l): void {
		$app = $this->stringParameter($notification, 'app');
		$version = $this->stringParameter($notification, 'version');
		$advisory = $this->stringParameter($notification, 'advisory');

		$notification
			->setParsedSubject(
				$l->t('Security advisory affects a pinned app version')
			)
			->setParsedMessage(
				$l->t('%1$s is on version %2$s, which is affected by advisory %3$s. Review and update to a safe version.', [$app, $version, $advisory])
			);
	}

	private function renderPatExpiring(INotification $notification, IL10N $l): void {
		$label = $this->stringParameter($notification, 'label');
		$forge = $this->stringParameter($notification, 'forge');
		$days = $this->intParameter($notification, 'daysRemaining');

		$notification
			->setParsedSubject(
				$l->t('Access token expires soon')
			)
			->setParsedMessage(
				$l->n(
					'"%1$s" (%2$s) expires in %3$d day. Renew it before it lapses.',
					'"%1$s" (%2$s) expires in %3$d days. Renew it before it lapses.',
					$days,
					[$label, $forge, $days]
				)
			);
	}

	private function renderPinDrift(INotification $notification, IL10N $l): void {
		$app = $this->stringParameter($notification, 'app');
		$pinnedVersion = $this->stringParameter($notification, 'pinnedVersion');
		$observedVersion = $this->stringParameter($notification, 'observedVersion');

		$notification
			->setParsedSubject(
				$l->t('A pinned app was updated outside Versioniq')
			)
			->setParsedMessage(
				$l->t('%1$s is pinned to %2$s but is now running %3$s. Re-pin to restore the pinned version, or accept the change.', [$app, $pinnedVersion, $observedVersion])
			);
	}

	private function renderPatExpired(INotification $notification, IL10N $l): void {
		$label = $this->stringParameter($notification, 'label');
		$forge = $this->stringParameter($notification, 'forge');

		$notification
			->setParsedSubject(
				$l->t('Access token expired')
			)
			->setParsedMessage(
				$l->t('"%1$s" (%2$s) has expired. Create a new token to keep private installs and discovery working.', [$label, $forge])
			);
	}

	private function renderAutoUpdateSuccess(INotification $notification, IL10N $l): void {
		$app = $this->stringParameter($notification, 'app');
		$fromVersion = $this->stringParameter($notification, 'fromVersion');
		$toVersion = $this->stringParameter($notification, 'toVersion');

		$notification
			->setParsedSubject(
				$l->t('App automatically updated')
			)
			->setParsedMessage(
				$fromVersion !== ''
					? $l->t('%1$s was automatically updated from %2$s to %3$s.', [$app, $fromVersion, $toVersion])
					: $l->t('%1$s was automatically installed at %2$s.', [$app, $toVersion])
			);
	}

	private function renderAutoUpdateFailure(INotification $notification, IL10N $l): void {
		$app = $this->stringParameter($notification, 'app');
		$targetVersion = $this->stringParameter($notification, 'targetVersion');
		$hint = $this->stringParameter($notification, 'hint');

		$notification
			->setParsedSubject(
				$l->t('Automatic update failed')
			)
			->setParsedMessage(
				$l->t('%1$s could not be automatically updated to %2$s. %3$s', [$app, $targetVersion, $hint])
			);
	}

	private function renderAdvisoryDigest(INotification $notification, IL10N $l): void {
		$apps = $this->intParameter($notification, 'apps');
		$advisories = $this->intParameter($notification, 'advisories');

		$notification
			->setParsedSubject(
				$l->t('Weekly security advisory digest')
			)
			->setParsedMessage(
				$l->n(
					'%1$d installed app has published security advisories (%2$d in total) that do not affect the installed version. Review them on the Versioniq Apps tab.',
					'%1$d installed apps have published security advisories (%2$d in total) that do not affect the installed versions. Review them on the Versioniq Apps tab.',
					$apps,
					[$apps, $advisories]
				)
			);
	}

	/**
	 * The named subject parameter as its string value, or ''.
	 */
	private function stringParameter(INotification $notification, string $key): string {
		/** @var mixed $value */
		$value = $notification->getSubjectParameters()[$key] ?? null;

		return is_string($value) ? $value : '';
	}

	/**
	 * The named subject parameter as its int value, or 0.
	 */
	private function intParameter(INotification $notification, string $key): int {
		$parameters = $notification->getSubjectParameters();

		return isset($parameters[$key]) && is_int($parameters[$key]) ? $parameters[$key] : 0;
	}
}
