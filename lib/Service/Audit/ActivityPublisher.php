<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Audit;

use OCA\Versioniq\Activity\Provider;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Db\AuditEntry;
use OCP\Activity\IManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Publishes a recorded install, rollback, pin, unpin or policy change to the
 * activity stream of every admin (audit-attribution-and-forwarding D4), after
 * the audit row is written. Best-effort: a failure is logged and never reaches
 * the operation; with the activity app disabled publishing does nothing.
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-audit-rows-reach-the-platforms-audit-log-and-activity-stream
 */
class ActivityPublisher {
	public function __construct(
		private IManager $activityManager,
		private IGroupManager $groupManager,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Publish the entry to each admin's stream when it is a successful version change.
	 *
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-audit-rows-reach-the-platforms-audit-log-and-activity-stream
	 */
	public function publish(AuditEntry $entry): void {
		$subject = self::subjectOf(entry: $entry);
		if ($subject === null) {
			return;
		}

		try {
			foreach ($this->adminUids() as $uid) {
				$this->activityManager->publish($this->event(entry: $entry, subject: $subject, affectedUser: $uid));
			}
		} catch (\Throwable $error) {
			$this->logger->warning('Versioniq: publishing a version change to the activity stream failed', ['exception' => $error]);
		}
	}

	/**
	 * The activity subject for a successful version change, or null for anything else.
	 */
	public static function subjectOf(AuditEntry $entry): ?string {
		if ($entry->getStatus() !== AuditLogger::STATUS_SUCCESS) {
			return null;
		}

		return match ($entry->getOperation()) {
			AuditLogger::OPERATION_INSTALL => self::isRollback(entry: $entry) ? Provider::SUBJECT_ROLLBACK : Provider::SUBJECT_INSTALL,
			AuditLogger::OPERATION_PIN => Provider::SUBJECT_PIN,
			AuditLogger::OPERATION_UNPIN => Provider::SUBJECT_UNPIN,
			AuditLogger::OPERATION_POLICY_SET => Provider::SUBJECT_POLICY_SET,
			AuditLogger::OPERATION_POLICY_CLEAR => Provider::SUBJECT_POLICY_CLEAR,
			default => null,
		};
	}

	private static function isRollback(AuditEntry $entry): bool {
		$from = (string)$entry->getFromVersion();
		$to = (string)$entry->getToVersion();

		return $from !== '' && $to !== '' && version_compare($to, $from, '<');
	}

	private function event(AuditEntry $entry, string $subject, string $affectedUser): \OCP\Activity\IEvent {
		return $this->activityManager->generateEvent()
			->setApp(Application::APP_ID)
			->setType(Provider::TYPE)
			->setAffectedUser($affectedUser)
			->setAuthor($entry->getActorUid())
			->setTimestamp($this->timeFactory->getTime())
			->setObject('app', $entry->getAppId(), $entry->getAppId())
			->setSubject($subject, [
				'actor' => $entry->getActorUid(),
				'app' => $entry->getAppId(),
				'from' => $entry->getFromVersion() ?? '',
				'to' => $entry->getToVersion() ?? '',
				'level' => self::levelOf(entry: $entry),
			]);
	}

	/** The level a policy row set, read from its `level: before -> after` message. */
	private static function levelOf(AuditEntry $entry): string {
		if ($entry->getOperation() !== AuditLogger::OPERATION_POLICY_SET) {
			return '';
		}

		$matched = preg_match('/level: \S+ -> (\S+)/', (string)$entry->getMessage(), $match);

		return $matched === 1 ? $match[1] : '';
	}

	/** @return list<string> */
	private function adminUids(): array {
		$uids = [];
		foreach ($this->groupManager->get('admin')?->getUsers() ?? [] as $user) {
			$uids[] = $user->getUID();
		}

		return array_values(array_unique($uids));
	}
}
