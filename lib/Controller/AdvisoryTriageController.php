<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Controller;

use OCA\Versioniq\Service\Advisory\AdvisoryTriageService;
use OCA\Versioniq\Service\Advisory\Dismissal;
use OCA\Versioniq\Service\Advisory\TriageRefusal;
use OCA\Versioniq\Service\Advisory\TriageTarget;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Dismisses, reopens and assigns one stored advisory (advisories-triage, design D4).
 *
 * Admin-only twice over, like SettingsController: no NoAdminRequired
 * attribute, and an explicit isAdmin() guard that runs before anything is
 * read. Every route is password-confirmed and answers with the triage map
 * after the change.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 *
 * @psalm-suppress UnusedClass
 */
class AdvisoryTriageController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private AdvisoryTriageService $triage,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Dismisses one advisory of one app, or of the server, with a reason (password-confirmed)
	 *
	 * Body: appId, advisoryId, reason (not_used, inaccurate, tolerable_risk, mitigated or fix_planned),
	 * comment (optional, at most 1000 characters) and until (optional end date; fix_planned needs one).
	 *
	 * @return DataResponse<Http::STATUS_OK, array{triage: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The triage map after the dismissal
	 * 400: The reason, comment or end date cannot be accepted; nothing was stored
	 * 403: Caller is not an administrator
	 * 404: The stored advisory check holds no such advisory for this app
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/advisory-triage/dismiss')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/advisory-triage/dismiss', postfix: 'v1')]
	public function dismiss(): DataResponse {
		$target = $this->target();
		if ($target === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(fn (): array => $this->triage->dismiss($target, Dismissal::from([
			'reason' => $this->field(name: 'reason'),
			'comment' => $this->field(name: 'comment'),
			'until' => $this->field(name: 'until'),
		])));
	}

	/**
	 * Reopens a dismissed advisory, so it counts again (password-confirmed)
	 *
	 * Body: appId, advisoryId.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{triage: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The triage map after the reopen
	 * 403: Caller is not an administrator
	 * 404: The stored advisory check holds no such advisory for this app
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/advisory-triage/reopen')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/advisory-triage/reopen', postfix: 'v1')]
	public function reopen(): DataResponse {
		$target = $this->target();
		if ($target === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(fn (): array => $this->triage->reopen($target));
	}

	/**
	 * Assigns an advisory to an admin or a group, or unassigns it (password-confirmed)
	 *
	 * Body: appId, advisoryId, assignee (user:{uid} of an admin, group:{gid}, or empty to unassign).
	 * The owners are notified; for a group, only its admin members.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{triage: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The triage map after the assignment
	 * 400: The assignee is not an admin user or an existing group; nothing was stored
	 * 403: Caller is not an administrator
	 * 404: The stored advisory check holds no such advisory for this app
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/advisory-triage/assign')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/advisory-triage/assign', postfix: 'v1')]
	public function assign(): DataResponse {
		$target = $this->target();
		if ($target === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return $this->answer(fn (): array => $this->triage->assign($target, $this->field(name: 'assignee')));
	}

	/**
	 * The advisory the request names and the admin acting, or null when the
	 * caller is not an administrator.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	private function target(): ?TriageTarget {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return null;
		}

		return new TriageTarget($this->field(name: 'appId'), $this->field(name: 'advisoryId'), $user->getUID());
	}

	/**
	 * Runs one decision and answers with the map, or with the refusal.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param callable(): array<string, array<string, mixed>> $decide
	 * @return DataResponse<Http::STATUS_OK, array{triage: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 */
	private function answer(callable $decide): DataResponse {
		try {
			return new DataResponse(['triage' => $decide()]);
		} catch (TriageRefusal $refusal) {
			/** @var Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND $status */
			$status = $refusal->status();

			return new DataResponse(['message' => $refusal->getMessage()], $status);
		}
	}

	/**
	 * A request field as trimmed text, or '' when absent or not text.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	private function field(string $name): string {
		/** @var mixed $value */
		$value = $this->request->getParam($name);

		return is_string($value) === true ? trim($value) : '';
	}
}
