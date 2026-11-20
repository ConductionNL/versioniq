<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Snapshot;

/**
 * How a rollback run goes (install-bulk-rollback design D5): a dry run checks
 * the downgrades and installs nothing, `movePins` also runs the pinned steps,
 * `continue` goes on after a failure, and `onStep` hears every outcome.
 *
 * @psalm-api
 */
final class RunMode {
	/** @var (\Closure(array<string, mixed>): void)|null */
	private ?\Closure $onStep;

	/**
	 * @param (callable(array<string, mixed>): void)|null $onStep Called after each step with its outcome.
	 */
	public function __construct(
		public readonly bool $dryRun = false,
		public readonly bool $movePins = false,
		public readonly bool $continue = false,
		?callable $onStep = null,
	) {
		$this->onStep = $onStep === null ? null : \Closure::fromCallable($onStep);
	}

	/**
	 * Tells the listener about one step's outcome.
	 *
	 * @param array<string, mixed> $outcome
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	public function notify(array $outcome): void {
		if ($this->onStep !== null) {
			($this->onStep)($outcome);
		}
	}
}
