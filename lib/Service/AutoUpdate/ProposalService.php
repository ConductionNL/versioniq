<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\Service\Policy\Policy;

/**
 * Works out, after each availability sweep, the update every app's policy
 * would install at the next due opening of the window, from the version lists
 * the sweep already fetched (no source call of its own), and stores them in
 * {@see ProposalStore}.
 *
 * An announced or approved proposal keeps its target version when a newer
 * one appears (design D2); a proposal that was only planned moves on. With a
 * notice period, a new target version is announced once, to the admins and
 * the notice group, and is planned no earlier than the notice period after
 * that announcement.
 *
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 *
 * @psalm-api
 */
class ProposalService {
	public const REASON_PINNED = 'pinned';
	public const REASON_BLOCKED = 'blocked';
	public const REASON_NO_CANDIDATE = 'no_candidate';
	public const REASON_SOURCE_ERROR = 'source_error';

	public function __construct(
		private AutoUpdateStores $stores,
		private ProposalStore $proposals,
		private CandidateSelector $selector,
		private AutoUpdateNotifier $notifier,
	) {
	}

	/**
	 * Recomputes and stores the proposals for every app with a policy.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 * @param array<string, array{installedVersion: string, versions: list<array<string, mixed>>|null}> $versionLists per app, from the sweep
	 */
	public function refresh(array $versionLists, \DateTimeImmutable $now): void {
		$previous = $this->proposals->read()['proposals'];
		$proposals = [];
		$reasons = [];

		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE || !isset($versionLists[$appId])) {
				continue;
			}
			$outcome = $this->proposalFor($appId, $policy, $versionLists[$appId], $previous[$appId] ?? null, $now);
			if ($outcome instanceof ProposedUpdate) {
				$proposals[$appId] = $outcome;
			} else {
				$reasons[$appId] = $outcome;
			}
		}

		$this->proposals->save($proposals, $reasons, $now->getTimestamp());
	}

	/**
	 * The proposal for one app, or the reason it has none.
	 *
	 * @param array{installedVersion: string, versions: list<array<string, mixed>>|null} $listing
	 */
	private function proposalFor(string $appId, Policy $policy, array $listing, ?ProposedUpdate $previous, \DateTimeImmutable $now): ProposedUpdate|string {
		if ($this->stores->pinStore->get($appId) !== null) {
			return self::REASON_PINNED;
		}
		if ($listing['versions'] === null) {
			return self::REASON_SOURCE_ERROR;
		}

		$installed = $listing['installedVersion'];
		$notes = self::notesByVersion($listing['versions']);
		$candidate = $this->selector->select($installed, array_map('strval', array_keys($notes)), $policy->level);
		if ($candidate === null) {
			return self::REASON_NO_CANDIDATE;
		}
		if ($this->stores->attemptLedger->hasAttempted($appId, $candidate)) {
			return self::REASON_BLOCKED;
		}

		$target = $this->keptTarget($appId, $previous, $installed, $notes) ?? $candidate;
		$same = $previous !== null && $previous->toVersion === $target && $previous->fromVersion === $installed;
		$proposal = new ProposedUpdate(
			$appId,
			$installed,
			$target,
			ProposedUpdate::kindOf($installed, $target),
			ProposedUpdate::STATUS_PLANNED,
			$same ? $previous->proposedAt : self::iso($now),
			null,
			$same ? $previous->announcedAt : null,
			ProposedUpdate::excerpt($notes[$target] ?? ''),
		);

		return $this->scheduled($proposal, $policy, $now);
	}

	/**
	 * The target an announced or approved proposal keeps (D2), when it is
	 * still listed, still newer than the installed version, not attempted and
	 * not declined; null otherwise.
	 *
	 * @param array<string, string> $notes
	 */
	private function keptTarget(string $appId, ?ProposedUpdate $previous, string $installed, array $notes): ?string {
		if ($previous === null || ($previous->announcedAt === null && $previous->status !== ProposedUpdate::STATUS_APPROVED)) {
			return null;
		}
		$kept = $previous->toVersion;
		$valid = array_key_exists($kept, $notes)
			&& version_compare($kept, $installed, '>')
			&& !$this->stores->attemptLedger->hasAttempted($appId, $kept)
			&& $this->proposals->declineOf($appId, $kept) === null;

		return $valid ? $kept : null;
	}

	/**
	 * The proposal with its announcement, planned time and status.
	 */
	private function scheduled(ProposedUpdate $proposal, Policy $policy, \DateTimeImmutable $now): ProposedUpdate {
		$decline = $this->proposals->declineOf($proposal->appId, $proposal->toVersion);
		if ($decline !== null) {
			return $proposal->with(ProposedUpdate::STATUS_DECLINED, null, $proposal->announcedAt, $decline);
		}

		$noticeDays = $this->stores->settingsStore->getNoticeDays();
		$announcedAt = $proposal->announcedAt;
		if ($noticeDays > 0 && $announcedAt === null) {
			$announcedAt = self::iso($now);
		}

		$earliest = $now;
		if ($noticeDays > 0 && $announcedAt !== null) {
			$noticeEnds = (new \DateTimeImmutable($announcedAt))->add(new \DateInterval('P' . $noticeDays . 'D'));
			$earliest = $noticeEnds > $now ? $noticeEnds : $now;
		}
		$plannedFor = $this->nextOpening($earliest);

		$approval = $this->proposals->approval($proposal->appId);
		$status = match (true) {
			($approval['version'] ?? null) === $proposal->toVersion => ProposedUpdate::STATUS_APPROVED,
			$policy->requireApproval => ProposedUpdate::STATUS_AWAITING_APPROVAL,
			$earliest > $now => ProposedUpdate::STATUS_IN_NOTICE,
			default => ProposedUpdate::STATUS_PLANNED,
		};
		$decision = $status === ProposedUpdate::STATUS_APPROVED && $approval !== null ? ['by' => $approval['by'], 'at' => $approval['at']] : null;
		$scheduled = $proposal->with($status, $plannedFor, $announcedAt, $decision);

		if ($announcedAt !== null && $proposal->announcedAt === null) {
			$this->notifier->notifyPlanned($scheduled, $this->stores->settingsStore->getNoticeGroup());
		}

		return $scheduled;
	}

	/**
	 * The first opening of the configured window at or after `$from`, read in
	 * the window's time zone, as an ISO time; null when the window never opens.
	 */
	private function nextOpening(\DateTimeImmutable $from): ?string {
		$zone = $this->stores->settingsStore->getTimeZoneName();
		$opening = AutoUpdateWindow::nextOpening(
			$this->stores->settingsStore->getWindow(),
			$from->setTimezone(new \DateTimeZone($zone !== '' ? $zone : 'UTC')),
		);

		return $opening === null ? null : self::iso($opening);
	}

	/**
	 * Each listed version with its changelog, keyed by version.
	 *
	 * @param list<array<string, mixed>> $versions
	 * @return array<string, string>
	 */
	private static function notesByVersion(array $versions): array {
		$notes = [];
		foreach ($versions as $entry) {
			/** @var mixed $version */
			$version = $entry['version'] ?? null;
			/** @var mixed $changelog */
			$changelog = $entry['changelog'] ?? null;
			$version = is_scalar($version) ? trim((string)$version) : '';
			if ($version !== '') {
				$notes[$version] = is_string($changelog) ? $changelog : '';
			}
		}

		return $notes;
	}

	private static function iso(\DateTimeInterface $time): string {
		return $time->format(\DateTimeInterface::ATOM);
	}
}
