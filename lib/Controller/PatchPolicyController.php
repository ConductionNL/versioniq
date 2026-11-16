<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Controller;

use InvalidArgumentException;
use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Audit\AdminChangeAuditInterface;
use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\PatchPolicyServices;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The patch policy on the Settings tab (autoupdate-security-first D3, D5):
 * read it with its sentences, and record it, admin-only and
 * password-confirmed, with an audit entry on every change.
 *
 * @psalm-suppress UnusedClass
 */
class PatchPolicyController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private PatchPolicyServices $policy,
		private ApiCaller $caller,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Reads the patch policy, its statement lines and the allowed values
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The patch policy
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	#[ApiRoute(verb: 'GET', url: '/api/patch-policy')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/patch-policy', postfix: 'v1')]
	public function patchPolicy(): DataResponse {
		if ($this->caller->adminUid() === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse($this->view($this->policy->store->effective()));
	}

	/**
	 * Records the patch policy (password-confirmed); validates every field
	 * before writing and writes nothing on 400
	 *
	 * @param array<string, array{deadlineDays?: int|null, handling?: string}> $security Per advisory severity (critical, high, medium, low, unknown): a deadline of 1 to 365 days or null, and a handling (next_window, scheduled, manual); a left-out severity keeps the no-policy reading
	 * @param array<string, string> $regular Per regular kind (patch, minor, major): automatic or manual
	 * @param string $statement Free text, at most 4000 characters
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The stored patch policy
	 * 400: A field is not valid
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/patch-policy')]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/patch-policy', postfix: 'v1')]
	public function updatePatchPolicy(array $security = [], array $regular = [], string $statement = ''): DataResponse {
		$uid = $this->caller->adminUid();
		if ($uid === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->record(['security' => $security, 'regular' => $regular, 'statement' => $statement], $uid);
	}

	/**
	 * Validates and records the patch policy an administrator sent, with an
	 * audit entry when it changed; writes nothing on 400.
	 *
	 * @param array<string, mixed> $input The security, regular and statement fields.
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 */
	private function record(array $input, string $uid): DataResponse {
		$previous = $this->policy->store->effective();
		try {
			$next = PatchPolicy::fromArray($input)->recordedBy(
				$uid,
				$this->caller->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
			);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$changed = $next->changedFrom($previous);
		if ($changed === [] && $previous->isRecorded()) {
			// Nothing changed: keep who changed it last, and write no entry.
			return new DataResponse($this->view($previous));
		}

		$this->policy->store->write($next);
		$this->policy->auditLogger->recordAdminChange(
			$uid,
			AdminChangeAuditInterface::OPERATION_PATCH_POLICY,
			'Patch policy changed: ' . ($changed === [] ? 'recorded' : implode(', ', $changed)),
		);

		return new DataResponse($this->view($next));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function view(PatchPolicy $policy): array {
		return [
			'policy' => $policy->toArray(),
			'recorded' => $policy->isRecorded(),
			'statement' => $this->policy->statement->lines($policy),
			'allowed' => [
				'severities' => PatchPolicy::SEVERITIES,
				'securityHandlings' => PatchPolicy::SECURITY_HANDLINGS,
				'kinds' => PatchPolicy::KINDS,
				'regularHandlings' => PatchPolicy::REGULAR_HANDLINGS,
				'deadlineMinDays' => PatchPolicy::DEADLINE_MIN_DAYS,
				'deadlineMaxDays' => PatchPolicy::DEADLINE_MAX_DAYS,
				'statementMaxLength' => PatchPolicy::STATEMENT_MAX_LENGTH,
			],
		];
	}
}
