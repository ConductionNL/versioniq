<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service;

use OCP\Notification\INotification;

/**
 * One admin notification: its object, subject, link, and the warning logged
 * when it cannot be raised for an admin. Built step by step from
 * `new AdminNotice()`, each step returning a copy.
 */
final class AdminNotice {
	private string $objectType = '';
	private string $objectId = '';
	private string $subject = '';
	/** @var array<string, string> */
	private array $subjectParameters = [];
	private string $link = '';
	private string $logMessage = '';
	/** @var array<string, string> */
	private array $logContext = [];

	/**
	 * The notice with its notification object; see "Every auto-update outcome is reported" and "Admins are notified".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function withObject(string $type, string $id): self {
		$notice = clone $this;
		$notice->objectType = $type;
		$notice->objectId = $id;

		return $notice;
	}

	/**
	 * @param array<string, string> $parameters
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function withSubject(string $subject, array $parameters): self {
		$notice = clone $this;
		$notice->subject = $subject;
		$notice->subjectParameters = $parameters;

		return $notice;
	}

	/**
	 * The notice with the link its notification opens; see "Admins are notified".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function withLink(string $link): self {
		$notice = clone $this;
		$notice->link = $link;

		return $notice;
	}

	/**
	 * The warning logged when the notice fails for one admin; the context is
	 * followed by `uid` and `message`.
	 *
	 * @param array<string, string> $context
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function loggedAs(string $message, array $context): self {
		$notice = clone $this;
		$notice->logMessage = $message;
		$notice->logContext = $context;

		return $notice;
	}

	/**
	 * Sets the object, subject and link on a notification already addressed to one admin.
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function applyTo(INotification $notification): void {
		$notification
			->setObject($this->objectType, $this->objectId)
			->setSubject($this->subject, $this->subjectParameters)
			->setLink($this->link);
	}

	/**
	 * The warning logged when the notice fails for one admin.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function logMessage(): string {
		return $this->logMessage;
	}

	/**
	 * @return array<string, string>
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function logContext(string $uid, \Throwable $error): array {
		return $this->logContext + ['uid' => $uid, 'message' => $error->getMessage()];
	}
}
