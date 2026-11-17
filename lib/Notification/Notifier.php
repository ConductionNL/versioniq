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
 * `auto_update_failure` auto-update outcome notices, and the
 * `auto_update_group` notice of an update group run) into localized
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
	 * `auto_update_failure`, the weekly `advisory_digest`, `advisory_assigned`
	 * when an admin hands an advisory to an owner, and `server_eol` before the
	 * running server major leaves support.
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
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
			'auto_update_group' => $this->renderAutoUpdateGroup(...),
			// Sent weekly by AdvisoryDigestNotifier. Without this branch the
			// digest was stored but skipped by the bell every time (#428).
			'advisory_digest' => $this->renderAdvisoryDigest(...),
			'server_eol' => $this->renderServerEol(...),
			'new_release' => $this->renderNewRelease(...),
			'advisory_assigned' => $this->renderAdvisoryAssigned(...),
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

	/**
	 * "Update group {name}" and one line per member with its outcome, in
	 * install order (autoupdate-run-batching D3).
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	private function renderAutoUpdateGroup(INotification $notification, IL10N $l): void {
		/** @var mixed $members */
		$members = $notification->getSubjectParameters()['members'] ?? [];
		$lines = [];
		foreach (is_array($members) ? $members : [] as $member) {
			if (is_array($member)) {
				$lines[] = self::groupMemberLine($member, $l);
			}
		}

		$notification
			->setParsedSubject($l->t('Update group %1$s', [$this->stringParameter($notification, 'group')]))
			->setParsedMessage(implode("\n", $lines));
	}

	/**
	 * The line of one member of an update group's notification.
	 *
	 * @param array<array-key, mixed> $member
	 */
	private static function groupMemberLine(array $member, IL10N $l): string {
		$app = is_string($member['appId'] ?? null) ? $member['appId'] : '';
		$version = is_string($member['version'] ?? null) ? $member['version'] : '';

		return match ($member['outcome'] ?? null) {
			'installed' => $l->t('%1$s: updated to %2$s', [$app, $version]),
			'failed' => $l->t('%1$s: update to %2$s failed', [$app, $version]),
			default => $l->t('%1$s: not attempted', [$app]),
		};
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
	 * The running server major reaches end of life within 90 or 30 days.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
	 */
	private function renderServerEol(INotification $notification, IL10N $l): void {
		$major = $this->stringParameter($notification, 'major');
		$date = $this->stringParameter($notification, 'date');
		$days = $this->intParameter($notification, 'daysRemaining');

		$notification
			->setParsedSubject(
				$l->t('Nextcloud %1$s reaches end of life soon', [$major])
			)
			->setParsedMessage(
				$l->n(
					'Nextcloud %1$s stops receiving updates on %2$s, in %3$d day. Plan the upgrade to a maintained version.',
					'Nextcloud %1$s stops receiving updates on %2$s, in %3$d days. Plan the upgrade to a maintained version.',
					$days,
					[$major, $date, $days]
				)
			);
	}

	/**
	 * An advisory assigned to the recipient: names the app (or the server),
	 * the advisory and the admin who assigned it.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
	 */
	private function renderAdvisoryAssigned(INotification $notification, IL10N $l): void {
		$app = $this->stringParameter($notification, 'app');
		$advisory = $this->stringParameter($notification, 'advisory');
		$by = $this->stringParameter($notification, 'by');

		$notification
			->setParsedSubject(
				$l->t('A security advisory was assigned to you')
			)
			->setParsedMessage(
				$app === ':server'
					? $l->t('%1$s assigned advisory %2$s for the Nextcloud server to you. Review it on the Versioniq Advisories tab.', [$by, $advisory])
					: $l->t('%1$s assigned advisory %2$s for %3$s to you. Review it on the Versioniq Advisories tab.', [$by, $advisory, $app])
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
