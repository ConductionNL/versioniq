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

use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\AutoUpdate\ProposalStore;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUser;

/**
 * The proposed automatic updates: the stored preview of what the policies
 * would install at the next window, and the admin's approval or decline of
 * one exact version. Every route is admin-only; the three that decide are
 * password-confirmed and audited. Reading never calls a source (issue #160):
 * the proposals are worked out by the availability sweep.
 *
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 *
 * @psalm-suppress UnusedClass
 */
class ProposalController extends OCSController {
	public const OPERATION_APPROVE = 'proposal_approve';
	public const OPERATION_DECLINE = 'proposal_decline';
	public const OPERATION_WITHDRAW = 'proposal_withdraw';

	private const REASON_MAX_LENGTH = 500;

	public function __construct(
		string $appName,
		IRequest $request,
		private ApiCaller $caller,
		private ProposalStore $proposals,
		private AuditLogger $auditLogger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Lists the stored proposed updates in planned order, the reason each app
	 * without one has none, and when they were computed (admin-only)
	 *
	 * @return DataResponse<Http::STATUS_OK, array{proposals: list<array<string, mixed>>, reasons: list<array{appId: string, reason: string}>, computedAt: ?string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The stored proposals
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/auto-update/proposals')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/auto-update/proposals', postfix: 'v1')]
	public function index(): DataResponse {
		if (!$this->adminUser() instanceof IUser) {
			return self::forbidden();
		}

		$stored = $this->proposals->read();
		$proposals = array_values($stored['proposals']);
		usort($proposals, static fn (ProposedUpdate $left, ProposedUpdate $right): int => self::byPlannedTime($left, $right));
		$reasons = [];
		foreach ($stored['reasons'] as $appId => $reason) {
			$reasons[] = ['appId' => $appId, 'reason' => $reason];
		}

		return new DataResponse([
			'proposals' => array_map(static fn (ProposedUpdate $proposal): array => $proposal->toArray(), $proposals),
			'reasons' => $reasons,
			'computedAt' => $stored['computedAt'] === null ? null : gmdate(\DateTimeInterface::ATOM, $stored['computedAt']),
		]);
	}

	/**
	 * Approves exactly this version of an app's proposed update (password-confirmed)
	 *
	 * @param string $appId The app the proposal is for
	 *
	 * @return DataResponse<Http::STATUS_OK, array{proposal: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The approved proposal
	 * 400: No version was given
	 * 403: Caller is not an administrator
	 * 404: There is no proposal for that version
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/auto-update/proposals/{appId}/approve')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/auto-update/proposals/{appId}/approve', postfix: 'v1')]
	public function approve(string $appId): DataResponse {
		$user = $this->adminUser();
		if (!$user instanceof IUser) {
			return self::forbidden();
		}
		$version = $this->stringParam('version');
		$proposal = $this->proposalOf($appId, $version);
		if (!$proposal instanceof ProposedUpdate) {
			return $proposal;
		}

		$at = $this->now();
		$this->proposals->approve($appId, $version, $user->getUID(), $at);
		$approved = $proposal->with(ProposedUpdate::STATUS_APPROVED, $proposal->plannedFor, $proposal->announcedAt, ['by' => $user->getUID(), 'at' => $at]);
		$this->proposals->replace($approved);
		$this->audit($user, $proposal, self::OPERATION_APPROVE, null);

		return new DataResponse(['proposal' => $approved->toArray()]);
	}

	/**
	 * Declines this version of an app's proposed update, with a reason
	 * (password-confirmed); the job skips it until the decline is withdrawn
	 *
	 * @param string $appId The app the proposal is for
	 *
	 * @return DataResponse<Http::STATUS_OK, array{proposal: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The declined proposal
	 * 400: No version was given
	 * 403: Caller is not an administrator
	 * 404: There is no proposal for that version
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/auto-update/proposals/{appId}/decline')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/auto-update/proposals/{appId}/decline', postfix: 'v1')]
	public function decline(string $appId): DataResponse {
		$user = $this->adminUser();
		if (!$user instanceof IUser) {
			return self::forbidden();
		}
		$version = $this->stringParam('version');
		$proposal = $this->proposalOf($appId, $version);
		if (!$proposal instanceof ProposedUpdate) {
			return $proposal;
		}

		$at = $this->now();
		$reason = mb_substr($this->stringParam('reason'), 0, self::REASON_MAX_LENGTH);
		$this->proposals->decline($appId, $version, $user->getUID(), $at, $reason);
		$declined = $proposal->with(ProposedUpdate::STATUS_DECLINED, null, $proposal->announcedAt, ['by' => $user->getUID(), 'at' => $at, 'reason' => $reason]);
		$this->proposals->replace($declined);
		$this->audit($user, $proposal, self::OPERATION_DECLINE, $reason !== '' ? $reason : null);

		return new DataResponse(['proposal' => $declined->toArray()]);
	}

	/**
	 * Withdraws the decline of a version (password-confirmed); the next
	 * proposal step may propose it again
	 *
	 * @param string $appId The app the decline is for
	 * @param string $version The declined version
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string, version: string, withdrawn: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The decline was withdrawn
	 * 403: Caller is not an administrator
	 * 404: That version is not declined
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/auto-update/proposals/{appId}/decline/{version}')]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/auto-update/proposals/{appId}/decline/{version}', postfix: 'v1')]
	public function withdraw(string $appId, string $version): DataResponse {
		$user = $this->adminUser();
		if (!$user instanceof IUser) {
			return self::forbidden();
		}
		if (!$this->proposals->withdrawDecline($appId, $version)) {
			return new DataResponse(['message' => 'That version is not declined.'], Http::STATUS_NOT_FOUND);
		}

		$proposal = $this->proposals->proposalFor($appId);
		$fromVersion = null;
		if ($proposal !== null && $proposal->toVersion === $version) {
			$fromVersion = $proposal->fromVersion;
			$this->proposals->replace($proposal->with(ProposedUpdate::STATUS_PLANNED, $proposal->plannedFor, $proposal->announcedAt, null));
		}
		$this->auditLogger->record(
			new AuditSubject($user->getUID(), $appId, self::OPERATION_WITHDRAW),
			new AuditedVersions($fromVersion, $version, null),
			AuditLogger::STATUS_SUCCESS,
		);

		return new DataResponse(['appId' => $appId, 'version' => $version, 'withdrawn' => true]);
	}

	/**
	 * The stored proposal for exactly this version, or the refusal: 400 without
	 * a version, 404 when the app's proposal is for another version or none.
	 *
	 * @return ProposedUpdate|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 */
	private function proposalOf(string $appId, string $version): ProposedUpdate|DataResponse {
		if ($version === '') {
			return new DataResponse(['message' => 'version is required.'], Http::STATUS_BAD_REQUEST);
		}
		$proposal = $this->proposals->proposalFor($appId);
		if ($proposal === null || $proposal->toVersion !== $version) {
			return new DataResponse(['message' => 'There is no proposed update to that version.'], Http::STATUS_NOT_FOUND);
		}

		return $proposal;
	}

	private function audit(IUser $user, ProposedUpdate $proposal, string $operation, ?string $message): void {
		$this->auditLogger->record(
			new AuditSubject($user->getUID(), $proposal->appId, $operation),
			new AuditedVersions($proposal->fromVersion, $proposal->toVersion, null),
			AuditLogger::STATUS_SUCCESS,
			$message,
		);
	}

	/**
	 * Proposals with a planned time first, earliest first; then by app id.
	 */
	private static function byPlannedTime(ProposedUpdate $left, ProposedUpdate $right): int {
		return [$left->plannedFor === null, $left->plannedFor ?? '', $left->appId]
			<=> [$right->plannedFor === null, $right->plannedFor ?? '', $right->appId];
	}

	private function stringParam(string $name): string {
		/** @var mixed $value */
		$value = $this->request->getParam($name, '');

		return is_string($value) ? trim($value) : '';
	}

	private function now(): string {
		return $this->caller->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
	}

	private function adminUser(): ?IUser {
		$user = $this->caller->userSession->getUser();
		if ($user === null || !$this->caller->groupManager->isAdmin($user->getUID())) {
			return null;
		}

		return $user;
	}

	/**
	 * @return DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 */
	private static function forbidden(): DataResponse {
		return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}
}
