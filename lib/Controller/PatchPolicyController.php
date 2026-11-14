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
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\PatchPolicyStatement;
use OCA\Versioniq\Service\Policy\PatchPolicyStore;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

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
		private PatchPolicyStore $store,
		private PatchPolicyStatement $statement,
		private AuditLogger $auditLogger,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ITimeFactory $timeFactory,
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
		if ($this->adminUid() === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse($this->view($this->store->effective()));
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
		$uid = $this->adminUid();
		if ($uid === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$previous = $this->store->effective();
		try {
			$next = PatchPolicy::fromArray(
				[
					'security' => $security,
					'regular' => $regular,
					'statement' => $statement,
				],
				$uid,
				$this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
			);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$changed = $next->changedFrom($previous);
		if ($changed === [] && $previous->isRecorded()) {
			// Nothing changed: keep who changed it last, and write no entry.
			return new DataResponse($this->view($previous));
		}

		$this->store->write($next);
		$this->auditLogger->record(
			new AuditSubject($uid, 'versioniq', AuditLogger::OPERATION_PATCH_POLICY),
			new AuditedVersions(null, null, null),
			AuditLogger::STATUS_SUCCESS,
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
			'statement' => $this->statement->lines($policy),
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

	/** The signed-in administrator's uid, or null for anyone else. */
	private function adminUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			return null;
		}

		return $user->getUID();
	}
}
