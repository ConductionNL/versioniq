<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\Advisory\AdvisoryNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Scheduled refresh that re-resolves security advisories for every installed
 * app and raises an admin notification for any advisory that newly affects a
 * pinned/installed version. It performs NO version change — it only reads
 * (via {@see AdvisoryService}) and notifies (via {@see AdvisoryNotifier}),
 * neither of which can install or unpin; the administrator stays in control.
 *
 * @psalm-api
 */
class AdvisoryRefreshJob extends TimedJob {

	/**
	 * Wall-clock ceiling for the sweep, in seconds.
	 *
	 * Generous on purpose. Correlation costs two external calls per app, so an
	 * instance with 88 enabled apps issues ~176 sequential calls; the whole
	 * point of doing that here rather than in a request is that there is time
	 * for it. Ten minutes covers a slow-but-working source while still
	 * bounding a source that hangs, so one unreachable forge cannot leave a
	 * cron job running forever.
	 *
	 * @var float
	 */
	private const SWEEP_BUDGET_SECONDS = 600.0;

	public function __construct(
		ITimeFactory $time,
		private AdvisoryRefreshSteps $steps,
		// NOT promoted to a property: the interval is read exactly once, here.
		// TimedJob fixes its interval at construction, so keeping a reference
		// would suggest the job can re-read the setting mid-life, which it
		// cannot — the next run after a change picks up the new value because
		// the job is constructed afresh.
		AdvisorySettingsStore $settings,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Administrator-settable: 6h default, 1–24 supported.
		$this->setInterval($settings->getIntervalSeconds());
	}

	/**
	 * Sweeps every enabled app, stores the snapshot the admin UI reads, and
	 * raises notifications for advisories that newly affect an installed or
	 * pinned version.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$saved = false;
		try {
			// The sweep passes its OWN budget. AdvisoryService's default is
			// sized for a request someone is waiting on (5s); inheriting it
			// here would clip this job to the first handful of apps and make
			// the stored snapshot — and the notifications derived from it —
			// quietly incomplete.
			$correlations = $this->steps->advisoryService->correlateAll(self::SWEEP_BUDGET_SECONDS);

			// Persist BEFORE notifying. Notification is the more failure-prone
			// half (it talks to the notifications app), and a snapshot the
			// admin UI can render is worth keeping even if the notification
			// half then throws.
			$this->steps->resultStore->save($correlations, $this->time->getTime());
			$saved = true;

			$unreached = count(array_filter(
				$correlations,
				static fn (array $entry): bool => ($entry['error'] ?? null) !== null,
			));
			if ($unreached > 0) {
				// Said out loud, because "correlated" and "correlated except
				// for 30 apps whose source did not answer" look identical in
				// a UI that only renders badges for what it found.
				$this->logger->warning('AdvisoryRefreshJob: some apps could not be correlated', [
					'unreached' => $unreached,
					'total' => count($correlations),
				]);
			}

			$fired = $this->steps->advisoryNotifier->notifyNewAdvisories($correlations);
			if ($fired > 0) {
				$this->logger->info('AdvisoryRefreshJob: raised advisory notifications', ['count' => $fired]);
			}

			// The weekly digest of everything that is NOT urgent. It rate-
			// limits itself, so calling it on every sweep is correct — the
			// sweep runs up to 24 times a day and the digest still sends once
			// a week.
			$digested = $this->steps->digestNotifier->sendIfDue($correlations, $this->time->getTime());
			if ($digested > 0) {
				$this->logger->info('AdvisoryRefreshJob: sent the weekly advisory digest', ['recipients' => $digested]);
			}
		} catch (\Throwable $error) {
			$this->logger->error('AdvisoryRefreshJob: refresh failed', ['message' => $error->getMessage()]);
			// Only the sweep itself failing makes the check line say so; a
			// notification that fails after a stored sweep does not.
			if (!$saved) {
				$this->recordFailure();
			}
		}
	}

	/**
	 * Says the sweep failed, so the check line does not read as checked.
	 * Recording it must not throw out of the job either.
	 */
	private function recordFailure(): void {
		try {
			$this->steps->resultStore->saveFailure($this->time->getTime());
		} catch (\Throwable $error) {
			$this->logger->error('AdvisoryRefreshJob: could not record the failed sweep', ['message' => $error->getMessage()]);
		}
	}
}
