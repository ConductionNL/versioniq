<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Policy;

use OCA\Versioniq\Service\Advisory\AdvisorySeverity;
use OCP\IL10N;

/**
 * The patch policy as plain sentences (autoupdate-security-first D5): one
 * per severity and per regular kind, generated from the stored values the job
 * reads, then the admin's free text and who changed it last. The page shows
 * these lines and offers the same lines as a text download.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
 */
class PatchPolicyStatement {
	public function __construct(
		private IL10N $l,
	) {
	}

	/**
	 * @return list<string>
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public function lines(PatchPolicy $policy): array {
		if (!$policy->isRecorded()) {
			return [$this->l->t('No patch policy is recorded yet. Every update follows the app\'s own update policy.')];
		}

		$lines = [];
		foreach (PatchPolicy::SEVERITIES as $severity) {
			$lines[] = $this->securityLine($severity, $policy->security[$severity]);
		}
		foreach (PatchPolicy::KINDS as $kind) {
			$lines[] = $this->regularLine($kind, $policy->regularHandling($kind));
		}
		if (trim($policy->statement) !== '') {
			$lines[] = trim($policy->statement);
		}
		$lines[] = $this->changedLine((string)$policy->setBy, (string)$policy->setAt);

		return $lines;
	}

	/**
	 * @param array{deadlineDays: ?int, handling: string} $row
	 */
	private function securityLine(string $severity, array $row): string {
		$line = $this->l->t('%1$s: %2$s', [$this->severityLabel($severity), $this->securityHandlingText($row['handling'])]);
		if ($row['deadlineDays'] !== null) {
			$line .= ' ' . $this->l->n('Deadline: %n day.', 'Deadline: %n days.', $row['deadlineDays']);
		}

		return $line;
	}

	private function regularLine(string $kind, string $handling): string {
		$text = $handling === PatchPolicy::HANDLING_MANUAL
			? $this->l->t('installed by hand only.')
			: $this->l->t('installed automatically, as each app\'s update policy allows.');

		return $this->l->t('%1$s: %2$s', [$this->kindLabel($kind), $text]);
	}

	private function changedLine(string $setBy, string $setAt): string {
		try {
			$date = (string)$this->l->l('date', new \DateTimeImmutable($setAt), ['width' => 'long']);
		} catch (\Exception) {
			$date = $setAt;
		}

		return $this->l->t('Last changed by %1$s on %2$s.', [$setBy, $date]);
	}

	private function severityLabel(string $severity): string {
		return match ($severity) {
			AdvisorySeverity::CRITICAL => $this->l->t('Critical security fixes'),
			AdvisorySeverity::HIGH => $this->l->t('High security fixes'),
			AdvisorySeverity::MEDIUM => $this->l->t('Medium security fixes'),
			AdvisorySeverity::LOW => $this->l->t('Low security fixes'),
			default => $this->l->t('Security fixes of unknown severity'),
		};
	}

	private function securityHandlingText(string $handling): string {
		return match ($handling) {
			PatchPolicy::HANDLING_NEXT_WINDOW => $this->l->t('installed at the next update window.'),
			PatchPolicy::HANDLING_MANUAL => $this->l->t('installed by hand only.'),
			default => $this->l->t('installed with the regular automatic updates.'),
		};
	}

	private function kindLabel(string $kind): string {
		return match ($kind) {
			PatchPolicy::KIND_MAJOR => $this->l->t('Major updates'),
			PatchPolicy::KIND_MINOR => $this->l->t('Minor updates'),
			default => $this->l->t('Patch updates'),
		};
	}
}
