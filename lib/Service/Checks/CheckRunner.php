<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Checks;

/**
 * Runs the update checks that match an app, in order, against an unpacked
 * package, and reports each one's name, kind, outcome and detail.
 *
 * Both installers call it in their dry-run branch, while the unpacked package
 * still exists. In a web request (context `web`) a command check whose
 * timeout is above {@see self::WEB_COMMAND_TIMEOUT} seconds is not run and is
 * reported as skipped, so a page never waits minutes; `occ` and the nightly
 * job use context `cli` and run every check.
 *
 * @psalm-api
 * @psalm-type CheckResult = array{name: string, kind: string, outcome: string, passed: bool, detail: string}
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
class CheckRunner {
	public const CONTEXT_WEB = 'web';
	public const CONTEXT_CLI = 'cli';

	public const OUTCOME_PASSED = 'passed';
	public const OUTCOME_FAILED = 'failed';
	public const OUTCOME_SKIPPED = 'skipped';

	/** The longest command check a web request runs, in seconds. */
	public const WEB_COMMAND_TIMEOUT = 60;

	public const SKIPPED_DETAIL = 'Runs from the command line and the nightly job only.';

	public function __construct(
		private CheckStore $store,
		private HttpVerdictCheck $verdictCheck,
		private CommandCheck $commandCheck,
	) {
	}

	/**
	 * Whether any check applies to the app.
	 *
	 * @spec openspec/changes/safety-update-checks/specs/auto-update-policies/spec.md#requirement-the-nightly-job-installs-only-after-the-attached-checks-pass
	 */
	public function matches(string $appId): bool {
		return $this->matching($appId) !== [];
	}

	/**
	 * Runs every check that applies to the app, in order.
	 *
	 * @return list<array{name: string, kind: string, outcome: string, passed: bool, detail: string}>
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function run(string $appId, string $fromVersion, string $toVersion, string $unpackedPath, string $context = self::CONTEXT_WEB): array {
		$subject = new CheckSubject($appId, $fromVersion, $toVersion, $unpackedPath);
		$results = [];
		foreach ($this->matching($appId) as $check) {
			$results[] = $this->runOne($check, $subject, $context);
		}

		return $results;
	}

	/**
	 * The first failed result, or null when none failed; a skipped check is not a failure.
	 *
	 * @param array<array-key, mixed> $results
	 * @return array{name: string, detail: string}|null
	 * @spec openspec/changes/safety-update-checks/specs/auto-update-policies/spec.md#requirement-the-nightly-job-installs-only-after-the-attached-checks-pass
	 */
	public static function firstFailure(array $results): ?array {
		foreach ($results as $result) {
			if (is_array($result) && ($result['outcome'] ?? null) === self::OUTCOME_FAILED) {
				return ['name' => (string)($result['name'] ?? ''), 'detail' => (string)($result['detail'] ?? '')];
			}
		}

		return null;
	}

	/**
	 * @return list<UpdateCheck>
	 */
	private function matching(string $appId): array {
		return array_values(array_filter($this->store->all(), static fn (UpdateCheck $check): bool => $check->appliesTo($appId)));
	}

	/**
	 * @return array{name: string, kind: string, outcome: string, passed: bool, detail: string}
	 */
	private function runOne(UpdateCheck $check, CheckSubject $subject, string $context): array {
		if ($check->kind === UpdateCheck::KIND_COMMAND && $context !== self::CONTEXT_CLI && $check->timeout > self::WEB_COMMAND_TIMEOUT) {
			return self::result($check, self::OUTCOME_SKIPPED, self::SKIPPED_DETAIL);
		}

		$outcome = $check->kind === UpdateCheck::KIND_COMMAND
			? $this->commandCheck->run($check, $subject)
			: $this->verdictCheck->run($check, $subject);

		return self::result($check, $outcome['passed'] ? self::OUTCOME_PASSED : self::OUTCOME_FAILED, $outcome['detail']);
	}

	/**
	 * @return array{name: string, kind: string, outcome: string, passed: bool, detail: string}
	 */
	private static function result(UpdateCheck $check, string $outcome, string $detail): array {
		return [
			'name' => $check->name,
			'kind' => $check->kind,
			'outcome' => $outcome,
			'passed' => $outcome === self::OUTCOME_PASSED,
			'detail' => $detail,
		];
	}
}
