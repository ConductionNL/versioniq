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
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

		if ($notification->getSubject() === 'pinned_to_vulnerable') {
			$parameters = $notification->getSubjectParameters();
			$app = is_string($parameters['app'] ?? null) ? $parameters['app'] : '';
			$version = is_string($parameters['version'] ?? null) ? $parameters['version'] : '';
			$advisory = is_string($parameters['advisory'] ?? null) ? $parameters['advisory'] : '';

			$notification
				->setParsedSubject(
					$l->t('Security advisory affects a pinned app version')
				)
				->setParsedMessage(
					$l->t('%1$s is on version %2$s, which is affected by advisory %3$s. Review and update to a safe version.', [$app, $version, $advisory])
				);

			return $notification;
		}

		if ($notification->getSubject() === 'pat_expiring') {
			$parameters = $notification->getSubjectParameters();
			$label = is_string($parameters['label'] ?? null) ? $parameters['label'] : '';
			$forge = is_string($parameters['forge'] ?? null) ? $parameters['forge'] : '';
			$days = is_int($parameters['daysRemaining'] ?? null) ? $parameters['daysRemaining'] : 0;

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

			return $notification;
		}

		if ($notification->getSubject() === 'pin_drift') {
			$parameters = $notification->getSubjectParameters();
			$app = is_string($parameters['app'] ?? null) ? $parameters['app'] : '';
			$pinnedVersion = is_string($parameters['pinnedVersion'] ?? null) ? $parameters['pinnedVersion'] : '';
			$observedVersion = is_string($parameters['observedVersion'] ?? null) ? $parameters['observedVersion'] : '';

			$notification
				->setParsedSubject(
					$l->t('A pinned app was updated outside Versioniq')
				)
				->setParsedMessage(
					$l->t('%1$s is pinned to %2$s but is now running %3$s. Re-pin to restore the pinned version, or accept the change.', [$app, $pinnedVersion, $observedVersion])
				);

			return $notification;
		}

		if ($notification->getSubject() === 'pat_expired') {
			$parameters = $notification->getSubjectParameters();
			$label = is_string($parameters['label'] ?? null) ? $parameters['label'] : '';
			$forge = is_string($parameters['forge'] ?? null) ? $parameters['forge'] : '';

			$notification
				->setParsedSubject(
					$l->t('Access token expired')
				)
				->setParsedMessage(
					$l->t('"%1$s" (%2$s) has expired. Create a new token to keep private installs and discovery working.', [$label, $forge])
				);

			return $notification;
		}

		if ($notification->getSubject() === 'auto_update_success') {
			$parameters = $notification->getSubjectParameters();
			$app = is_string($parameters['app'] ?? null) ? $parameters['app'] : '';
			$fromVersion = is_string($parameters['fromVersion'] ?? null) ? $parameters['fromVersion'] : '';
			$toVersion = is_string($parameters['toVersion'] ?? null) ? $parameters['toVersion'] : '';

			$notification
				->setParsedSubject(
					$l->t('App automatically updated')
				)
				->setParsedMessage(
					$fromVersion !== ''
						? $l->t('%1$s was automatically updated from %2$s to %3$s.', [$app, $fromVersion, $toVersion])
						: $l->t('%1$s was automatically installed at %2$s.', [$app, $toVersion])
				);

			return $notification;
		}

		if ($notification->getSubject() === 'auto_update_failure') {
			$parameters = $notification->getSubjectParameters();
			$app = is_string($parameters['app'] ?? null) ? $parameters['app'] : '';
			$targetVersion = is_string($parameters['targetVersion'] ?? null) ? $parameters['targetVersion'] : '';
			$hint = is_string($parameters['hint'] ?? null) ? $parameters['hint'] : '';

			$notification
				->setParsedSubject(
					$l->t('Automatic update failed')
				)
				->setParsedMessage(
					$l->t('%1$s could not be automatically updated to %2$s. %3$s', [$app, $targetVersion, $hint])
				);

			return $notification;
		}

		if ($notification->getSubject() === 'advisory_digest') {
			// Sent weekly by AdvisoryDigestNotifier. Without this branch the
			// digest was stored but skipped by the bell every time (#428).
			$parameters = $notification->getSubjectParameters();
			$apps = is_int($parameters['apps'] ?? null) ? $parameters['apps'] : 0;
			$advisories = is_int($parameters['advisories'] ?? null) ? $parameters['advisories'] : 0;

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

			return $notification;
		}

		throw new UnknownNotificationException();
	}
}
