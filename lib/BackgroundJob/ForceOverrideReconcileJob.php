<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\Installer\ForceOverrideReconciler;
use OCP\BackgroundJob\TimedJob;

/**
 * Hourly: lifts the `app_install_overwrite` overrides Versioniq set once the
 * server runs a higher major; see {@see ForceOverrideReconciler}. Registered
 * via `<background-jobs>` in `appinfo/info.xml`.
 *
 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
 * @psalm-api
 */
class ForceOverrideReconcileJob extends TimedJob {
	private const INTERVAL_SECONDS = 60 * 60;

	public function __construct(
		JobRuntime $runtime,
		private ForceOverrideReconciler $reconciler,
	) {
		parent::__construct($runtime->time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$this->reconciler->reconcile();
	}
}
