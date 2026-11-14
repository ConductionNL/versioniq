<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Lifecycle;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\NotifiesAdmins;
use OCA\Versioniq\Service\ResolvesAdminUids;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Warns every admin 90 and 30 days before the running server major reaches
 * its end of life, once per threshold.
 *
 * The thresholds already sent are kept in app config per major and date, so
 * a feed that moves the date warns again for the new date.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
 */
class ServerEolWarner {
	use NotifiesAdmins;
	use ResolvesAdminUids;

	/** The thresholds already sent, as `major|date|threshold` entries. */
	public const KEY_WARNED = 'lifecycle.eol_warned';

	/** Days before the date, from the latest to the earliest warning. */
	public const THRESHOLDS = [30, 90];

	public function __construct(
		private ServerEolFeed $feed,
		private ServerVersionProvider $serverVersion,
		private IAppConfig $config,
		private AdminNotificationServices $admin,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Send the warning the running major is due, if any, and say which.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
	 * @return int|null The threshold sent, or null when nothing was due.
	 */
	public function warnIfDue(): ?int {
		$major = (int)$this->serverVersion->current();
		$date = $this->feed->eolDate($major);
		if ($major <= 0 || $date === null) {
			return null;
		}

		$days = $this->daysUntil($date);
		$threshold = self::thresholdFor($days);
		if ($threshold === null) {
			return null;
		}

		$entry = $major . '|' . $date . '|' . $threshold;
		$warned = $this->warned();
		if (in_array($entry, $warned, true)) {
			return null;
		}

		$this->notifyAdmins((new AdminNotice())
			->withObject('server_eol', (string)$major)
			->withSubject('server_eol', ['major' => (string)$major, 'date' => $date, 'daysRemaining' => $days])
			->loggedAs('ServerEolWarner: failed to notify an admin', ['major' => $major]));

		$warned[] = $entry;
		$this->config->setValueString(Application::APP_ID, self::KEY_WARNED, (string)json_encode($warned));

		return $threshold;
	}

	/**
	 * The smallest threshold the remaining days fall within, or null when the
	 * date is further away than the largest or has already passed.
	 */
	private static function thresholdFor(int $days): ?int {
		if ($days <= 0) {
			return null;
		}

		foreach (self::THRESHOLDS as $threshold) {
			if ($days <= $threshold) {
				return $threshold;
			}
		}

		return null;
	}

	/**
	 * Whole days from today (UTC) until the date.
	 */
	private function daysUntil(string $date): int {
		$today = $this->admin->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->setTime(0, 0);
		$eol = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));

		return (int)floor(($eol->getTimestamp() - $today->getTimestamp()) / 86400);
	}

	/**
	 * @return list<string>
	 */
	private function warned(): array {
		/** @var mixed $warned */
		$warned = json_decode($this->config->getValueString(Application::APP_ID, self::KEY_WARNED, '[]'), true);

		return is_array($warned) ? array_values(array_filter($warned, 'is_string')) : [];
	}
}
