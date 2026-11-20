<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;

/**
 * What a nightly run would install for one app, and what waits
 * (releases-minimum-age D3, D5, D6).
 *
 * The versions the policy level accepts are split into eligible ones and
 * ones younger than the minimum age; only the eligible ones go to the
 * candidate selector, so `next` is the newest eligible version even while a
 * newer one waits. An admin's exception (D5) and a security fix the advisory
 * snapshot recommends (D6) count as eligible; the level still applies to
 * both. An exception that has done its work is removed and audited.
 *
 * @psalm-type HeldVersion = array{version: string, releasedAt: string, eligibleAt: string, reason: string}
 * @psalm-type SecurityBypass = array{version: string, advisories: list<string>, reason: string}
 * @psalm-type Plan = array{next: ?string, heldBack: list<HeldVersion>, securityBypass: ?SecurityBypass, exception: ?string, eligible: list<string>, bypassed: ?string}
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 * @psalm-api
 */
class MinimumAgePlanner {
	public const REASON_MINIMUM_AGE = 'minimum_age';
	/** A security fix let through before the minimum age. */
	public const REASON_SECURITY_FIX = 'security_fix';
	/** A security fix the policy level does not reach, so it is not installed automatically. */
	public const REASON_OUTSIDE_LEVEL = 'outside_level';

	public function __construct(
		private CandidateSelector $candidateSelector,
		private MinimumAgeRule $minimumAgeRule,
		private MinAgeExceptionStore $exceptions,
		private AdvisoryResultStore $advisories,
		private AuditLogger $auditLogger,
	) {
	}

	/**
	 * The plan for one app.
	 *
	 * The query carries the app with its level and effective minimum age in
	 * days (the policy's, else the instance value), the installed version and
	 * the versions the app's source lists.
	 *
	 * @return Plan
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public function plan(AgeQuery $query): array {
		$appId = $query->target->appId;
		$installedVersion = $query->installedVersion;
		$level = $query->target->level;
		$minAgeDays = $query->target->minAgeDays;
		$now = $query->now();

		$exception = $this->liveException($query);
		$security = $this->securityFix($appId);

		$eligible = [];
		$heldBack = [];
		$bypassed = null;
		$excepted = null;
		foreach ($query->byVersion as $version => $entry) {
			$version = (string)$version;
			if ($this->candidateSelector->select($installedVersion, [$version], $level) !== $version) {
				continue;
			}
			if (!$this->minimumAgeRule->isHeld($appId, $entry, $minAgeDays, $now)) {
				$eligible[] = $version;
				continue;
			}
			if ($version === $exception) {
				$eligible[] = $version;
				$excepted = $version;
				continue;
			}
			if ($security !== null && $version === $security['version']) {
				$eligible[] = $version;
				$bypassed = $version;
				continue;
			}
			$releasedAt = $this->minimumAgeRule->releasedAt($appId, $entry, $now);
			$heldBack[] = [
				'version' => $version,
				'releasedAt' => $releasedAt->format(DATE_ATOM),
				'eligibleAt' => MinimumAgeRule::eligibleAt($releasedAt, $minAgeDays)->format(DATE_ATOM),
				'reason' => self::REASON_MINIMUM_AGE,
			];
		}
		usort($heldBack, static fn (array $a, array $b): int => version_compare($a['version'], $b['version']));

		$next = $this->candidateSelector->select($installedVersion, $eligible, $level);

		return [
			'next' => $next,
			'heldBack' => $heldBack,
			'securityBypass' => $this->securityBypass($query, ['security' => $security, 'bypassed' => $bypassed, 'next' => $next]),
			'exception' => $excepted,
			'eligible' => $eligible,
			'bypassed' => $bypassed,
		];
	}

	/**
	 * The app's exception version while it still has work to do; one that
	 * has done its work is removed here, with an audit row saying why.
	 *
	 */
	private function liveException(AgeQuery $query): ?string {
		$appId = $query->target->appId;
		$installedVersion = $query->installedVersion;
		$byVersion = $query->byVersion;
		$stored = $this->exceptions->get($appId);
		if ($stored === null) {
			return null;
		}
		$version = $stored['version'];

		$reason = match (true) {
			version_compare($installedVersion, $version, '>=') => 'version ' . $installedVersion . ' is installed',
			!isset($byVersion[$version]) => 'the source no longer lists it',
			!$this->minimumAgeRule->isHeld($appId, $byVersion[$version], $query->target->minAgeDays, $query->now()) => 'it reached the minimum age',
			default => null,
		};
		if ($reason === null) {
			return $version;
		}

		$this->exceptions->remove($appId);
		$this->auditLogger->record(
			new AuditSubject('', $appId, AuditLogger::OPERATION_SETTINGS),
			new AuditedVersions($installedVersion, $version, null),
			AuditLogger::STATUS_SUCCESS,
			sprintf('Minimum-age exception for %s removed: %s', $version, $reason),
		);

		return null;
	}

	/**
	 * The fix the advisory snapshot recommends for an app that runs a
	 * vulnerable version, with the advisory ids, or null.
	 *
	 * @return array{version: string, advisories: list<string>}|null
	 */
	private function securityFix(string $appId): ?array {
		/** @var mixed $correlation */
		$correlation = $this->advisories->read()['advisories'][$appId] ?? null;
		if (!is_array($correlation) || ($correlation['state'] ?? null) !== AdvisoryService::STATE_VULNERABLE) {
			return null;
		}
		$recommended = $correlation['recommendedVersion'] ?? null;
		if (!is_string($recommended) || $recommended === '') {
			return null;
		}

		$ids = [];
		/** @var mixed $advisory */
		foreach ((array)($correlation['advisories'] ?? []) as $advisory) {
			if (is_array($advisory) && is_string($advisory['id'] ?? null)) {
				$ids[] = $advisory['id'];
			}
		}

		return ['version' => $recommended, 'advisories' => $ids];
	}

	/**
	 * The bypass to report: the fix the run installs before the minimum age,
	 * or a listed, newer fix the level does not reach, so the plan says why
	 * it is not installed.
	 *
	 * @param array{security: array{version: string, advisories: list<string>}|null, bypassed: ?string, next: ?string} $found
	 *        The fix the advisories recommend, the fix let through early and the version the run takes.
	 * @return array{version: string, advisories: list<string>, reason: string}|null
	 */
	private function securityBypass(AgeQuery $query, array $found): ?array {
		$security = $found['security'];
		if ($security === null) {
			return null;
		}
		if ($found['bypassed'] !== null && $found['bypassed'] === $found['next']) {
			return $security + ['reason' => self::REASON_SECURITY_FIX];
		}
		$version = $security['version'];
		$installedVersion = $query->installedVersion;
		$inLevel = $this->candidateSelector->select($installedVersion, [$version], $query->target->level) === $version;
		if (!$inLevel && isset($query->byVersion[$version]) && version_compare($version, $installedVersion, '>')) {
			return $security + ['reason' => self::REASON_OUTSIDE_LEVEL];
		}

		return null;
	}
}
