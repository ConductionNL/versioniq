<?php

/**
 * Repair step for removing the background-job registrations left behind by the
 * move out of the retired `OCA\Versioniq\Cron` namespace.
 *
 * @category Repair
 * @package  OCA\Versioniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);


namespace OCA\Versioniq\Repair;

use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes the `oc_jobs` rows left behind when this app's background jobs moved
 * out of the retired `OCA\Versioniq\Cron` namespace into
 * `OCA\Versioniq\BackgroundJob` (ADR-100 Decision 3).
 *
 * WHY A REPAIR STEP IS REQUIRED, AND NOT OPTIONAL TIDYING.
 *
 * `appinfo/info.xml`'s `<job>` entries are a REGISTRATION instruction, not a
 * description of state. On upgrade Nextcloud ADDS any job it does not already
 * have; it never removes one whose class disappeared, because it has no way to
 * tell a renamed class from a class that is merely unavailable this boot.
 *
 * So a class rename leaves the instance holding BOTH: the new row, added from
 * the updated `info.xml`, and the old row still naming a class that no longer
 * exists. Measured on a live instance during the fleet-wide move — before this
 * step existed, `oc_jobs` carried
 * `OCA\OpenCatalogi\Cron\DirectorySync` and `…\Cron\RetentionEvaluation`
 * alongside their `BackgroundJob` replacements.
 *
 * The orphan is not inert. `\OC\BackgroundJob\JobList::buildJob()` cannot
 * instantiate a class that does not exist, so every cron tick that reaches the
 * row fails to build it, and the failure is logged rather than raised — the
 * quiet kind of broken. It also breaks anything that resolves a job by NAME
 * rather than by fully-qualified class: this app's own e2e helper looks the job
 * up with `class LIKE '%PinReconcileJob%' LIMIT 1`, which with two matching
 * rows and no ordering may return the dead one and silently execute nothing.
 * That is how the orphan was found.
 *
 * Idempotent: `IJobList::remove()` on an absent class is a no-op, so a fresh
 * install — which never had the old rows — passes through without change, and
 * re-running the step costs one DELETE that matches nothing.
 *
 * @psalm-suppress UnusedClass Nextcloud instantiates repair steps from
 *  the `<repair-steps>` block in appinfo/info.xml, which is XML — psalm
 *  reads PHP and therefore sees no caller. The sibling steps escape this
 *  only because unrelated docblocks happen to `{@see}` them, which is a
 *  coincidence rather than a contract.
 */
class RemoveRetiredCronJobs implements IRepairStep {

	/**
	 * The classes retired by the move, named in full and deliberately as
	 * literals.
	 *
	 * They are string constants rather than `SomeClass::class` because these
	 * classes NO LONGER EXIST — a `::class` reference would be a compile-time
	 * error, and that is precisely the point of the list.
	 *
	 * @var string[]
	 */
	private const RETIRED_JOB_CLASSES = [
		'OCA\Versioniq\Cron\PinReconcileJob',
		'OCA\Versioniq\Cron\PruneAuditJob',
		// The app_versions -> versioniq RENAME retires a whole namespace, and
		// this list previously covered only the Cron -> BackgroundJob move
		// within the new one. Nextcloud never removes a job row whose class
		// disappeared — it cannot tell a renamed class from one merely
		// unavailable this boot — so an instance carried over from the old id
		// keeps scheduling these forever.
		//
		// Measured on a live instance 2026-08-27, all five present with a
		// RECENT last_run, i.e. actively being scheduled and failing:
		'OCA\AppVersions\BackgroundJob\AdvisoryRefreshJob',
		'OCA\AppVersions\BackgroundJob\AutoUpdateJob',
		'OCA\AppVersions\BackgroundJob\PatExpiryWarningJob',
		'OCA\AppVersions\Cron\PinReconcileJob',
		'OCA\AppVersions\Cron\PruneAuditJob',
	];

	/**
	 * @param IJobList $jobList The background job list.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name, as shown by `occ upgrade`.
	 *
	 * @return string The name.
	 *
	 * @spec exclude See the class docblock — no capability spec covers the
	 *  namespace move this step cleans up after.
	 */
	public function getName(): string {
		return 'Remove background-job registrations for the retired Versioniq\Cron namespace';
	}//end getName()

	/**
	 * Remove each retired job registration.
	 *
	 * Never raises. A repair step that aborts the upgrade over a job row would
	 * trade a dormant orphan for an instance that will not start, which is the
	 * worse failure — so a removal that goes wrong is reported and the step
	 * continues with the next class.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec exclude See the class docblock — no capability spec covers the
	 *  namespace move this step cleans up after.
	 *
	 * @psalm-suppress ArgumentTypeCoercion `IJobList::remove()` is typed for
	 *  callers REGISTERING a job, which hold the class. This step RETIRES one
	 *  and the class is gone by construction, so a class-string cannot exist.
	 *  Declared here rather than at the call site: psalm needs a docblock, and
	 *  phpcs forbids one before a statement.
	 */
	public function run(IOutput $output): void {
		foreach (self::RETIRED_JOB_CLASSES as $class) {
			try {
				// PHPStan: remove() is typed `class-string<IJob>|IJob`, and a
				// plain string is exactly what this step must pass — the
				// classes are GONE, which is the whole reason the row has to be
				// removed. A class-string is unobtainable by construction, and
				// remove() only ever uses the value as the `class` column to
				// delete on, so the narrower type is about callers registering
				// jobs, not callers retiring them.
				// @phpstan-ignore argument.type
				$this->jobList->remove($class);
				$output->info('Removed retired background job registration: ' . $class);
			} catch (Throwable $e) {
				// Reported, not raised — see the docblock above.
				$this->logger->warning(
					'[RemoveRetiredCronJobs] Could not remove ' . $class . ': ' . $e->getMessage(),
					['app' => 'versioniq', 'exception' => $e]
				);
				$output->warning('Could not remove ' . $class . ': ' . $e->getMessage());
			}
		}

	}//end run()
}//end class
