<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Settings\StoredSnapshot;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Keeps the proposed updates the last proposal step worked out, and the
 * admins' decisions on them.
 *
 * The proposals are a snapshot under `auto_update.proposals` (time computed
 * under `auto_update.proposals.checkedAt`): a snapshot that cannot be encoded
 * keeps the previous one, as {@see \OCA\Versioniq\Service\Advisory\AdvisoryResultStore}
 * does. The decisions live apart, under `auto_update.proposal_decisions`, so
 * the next proposal step never drops an approval or a decline: an approval
 * per app for one exact version, and any number of declined versions per app
 * until each is withdrawn.
 *
 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
 *
 * @psalm-api
 */
class ProposalStore {
	public const KEY = 'auto_update.proposals';
	public const DECISIONS_KEY = 'auto_update.proposal_decisions';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Stores the proposals and the reasons for apps without one, with the
	 * time they were computed. Keeps the previous snapshot when this one
	 * cannot be encoded.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 * @param array<string, ProposedUpdate> $proposals keyed by app id
	 * @param array<string, string> $reasons keyed by app id
	 */
	public function save(array $proposals, array $reasons, int $computedAt): void {
		$map = [
			'proposals' => array_map(static fn (ProposedUpdate $proposal): array => $proposal->toArray(), $proposals),
			'reasons' => $reasons,
		];
		try {
			$this->snapshot()->write($map, $computedAt);
		} catch (\JsonException $error) {
			$this->logger->error('ProposalStore: could not encode the proposed updates; keeping the previous ones', [
				'message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * The stored proposals, the reasons for apps without one, and when they
	 * were computed (null when no proposal step has run yet).
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 * @return array{proposals: array<string, ProposedUpdate>, reasons: array<string, string>, computedAt: ?int}
	 */
	public function read(): array {
		try {
			$map = $this->snapshot()->readMap();
		} catch (\JsonException $error) {
			$this->logger->warning('ProposalStore: stored proposals are not valid JSON; reporting none', ['message' => $error->getMessage()]);
			$map = null;
		}
		if ($map === null) {
			return ['proposals' => [], 'reasons' => [], 'computedAt' => null];
		}

		return [
			'proposals' => $this->hydrateProposals($map['proposals'] ?? null),
			'reasons' => self::stringMap($map['reasons'] ?? null),
			'computedAt' => $this->snapshot()->checkedAt(),
		];
	}

	/**
	 * The stored proposal for one app, or null.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function proposalFor(string $appId): ?ProposedUpdate {
		return $this->read()['proposals'][$appId] ?? null;
	}

	/**
	 * Replaces one app's stored proposal, keeping the rest and the time the
	 * snapshot was computed; used when an admin decides on it.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function replace(ProposedUpdate $proposal): void {
		$stored = $this->read();
		$stored['proposals'][$proposal->appId] = $proposal;
		$this->save($stored['proposals'], $stored['reasons'], $stored['computedAt'] ?? 0);
	}

	/**
	 * Records that an admin approved exactly this version for this app; it
	 * replaces an earlier approval of another version.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function approve(string $appId, string $version, string $by, string $at): void {
		$decisions = $this->decisions();
		$decisions[$appId] = ['approved' => ['version' => $version, 'by' => $by, 'at' => $at], 'declined' => $decisions[$appId]['declined'] ?? []];
		$this->writeDecisions($decisions);
	}

	/**
	 * The approval on record for an app: the version, who approved it and when.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 * @return array{version: string, by: string, at: string}|null
	 */
	public function approval(string $appId): ?array {
		return $this->decisions()[$appId]['approved'] ?? null;
	}

	/**
	 * Whether this exact version is approved for this app.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function isApproved(string $appId, string $version): bool {
		return ($this->approval($appId)['version'] ?? null) === $version;
	}

	/**
	 * Records that an admin declined this version for this app, with a reason.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function decline(string $appId, string $version, string $by, string $at, string $reason): void {
		$decisions = $this->decisions();
		$declined = $decisions[$appId]['declined'] ?? [];
		$declined[$version] = ['by' => $by, 'at' => $at, 'reason' => $reason];
		$decisions[$appId] = ['approved' => $decisions[$appId]['approved'] ?? null, 'declined' => $declined];
		$this->writeDecisions($decisions);
	}

	/**
	 * The decline on record for this version of this app, or null.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 * @return array{by: string, at: string, reason: string}|null
	 */
	public function declineOf(string $appId, string $version): ?array {
		return $this->decisions()[$appId]['declined'][$version] ?? null;
	}

	/**
	 * Withdraws the decline of this version; false when none was on record.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function withdrawDecline(string $appId, string $version): bool {
		$decisions = $this->decisions();
		if (!isset($decisions[$appId]['declined'][$version])) {
			return false;
		}
		unset($decisions[$appId]['declined'][$version]);
		$this->writeDecisions($decisions);

		return true;
	}

	/**
	 * The stored decisions per app, keeping only well-formed entries.
	 *
	 * @return array<string, array{approved: array{version: string, by: string, at: string}|null, declined: array<string, array{by: string, at: string, reason: string}>}>
	 */
	private function decisions(): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::DECISIONS_KEY, '');
		/** @var mixed $decoded */
		$decoded = $raw === '' ? [] : json_decode($raw, true);
		if (!is_array($decoded)) {
			$this->logger->warning('ProposalStore: stored decisions are not valid JSON; reading none');

			return [];
		}

		$decisions = [];
		/** @var mixed $entry */
		foreach ($decoded as $appId => $entry) {
			if (is_array($entry)) {
				$decisions[(string)$appId] = [
					'approved' => self::approvalFrom($entry['approved'] ?? null),
					'declined' => self::declinesFrom($entry['declined'] ?? null),
				];
			}
		}

		return $decisions;
	}

	/**
	 * @return array{version: string, by: string, at: string}|null
	 */
	private static function approvalFrom(mixed $stored): ?array {
		if (!is_array($stored)) {
			return null;
		}
		$fields = self::strings($stored, ['version', 'by', 'at']);

		return $fields === null ? null : ['version' => $fields['version'], 'by' => $fields['by'], 'at' => $fields['at']];
	}

	/**
	 * @return array<string, array{by: string, at: string, reason: string}>
	 */
	private static function declinesFrom(mixed $stored): array {
		$declines = [];
		/** @var mixed $decline */
		foreach (is_array($stored) ? $stored : [] as $version => $decline) {
			if (!is_array($decline)) {
				continue;
			}
			$fields = self::strings($decline, ['by', 'at']);
			if ($fields !== null) {
				/** @var mixed $reason */
				$reason = $decline['reason'] ?? '';
				$declines[(string)$version] = ['by' => $fields['by'], 'at' => $fields['at'], 'reason' => is_string($reason) ? $reason : ''];
			}
		}

		return $declines;
	}

	/**
	 * The named fields when every one is a string, else null.
	 *
	 * @param array<array-key, mixed> $stored
	 * @param list<string> $names
	 * @return array<string, string>|null
	 */
	private static function strings(array $stored, array $names): ?array {
		$fields = [];
		foreach ($names as $name) {
			/** @var mixed $value */
			$value = $stored[$name] ?? null;
			if (!is_string($value)) {
				return null;
			}
			$fields[$name] = $value;
		}

		return $fields;
	}

	/**
	 * @param array<string, array{approved: array{version: string, by: string, at: string}|null, declined: array<string, array{by: string, at: string, reason: string}>}> $decisions
	 */
	private function writeDecisions(array $decisions): void {
		$this->config->setValueString(Application::APP_ID, self::DECISIONS_KEY, (string)json_encode($decisions));
	}

	/**
	 * @return array<string, ProposedUpdate>
	 */
	private function hydrateProposals(mixed $stored): array {
		$proposals = [];
		foreach (is_array($stored) ? $stored : [] as $appId => $payload) {
			if (!is_array($payload)) {
				continue;
			}
			try {
				$proposals[(string)$appId] = ProposedUpdate::fromArray($payload);
			} catch (\InvalidArgumentException $error) {
				$this->logger->warning('ProposalStore: skipping a malformed stored proposal', ['appId' => $appId, 'message' => $error->getMessage()]);
			}
		}

		return $proposals;
	}

	/**
	 * @return array<string, string>
	 */
	private static function stringMap(mixed $stored): array {
		$map = [];
		/** @var mixed $value */
		foreach (is_array($stored) ? $stored : [] as $key => $value) {
			if (is_string($value)) {
				$map[(string)$key] = $value;
			}
		}

		return $map;
	}

	private function snapshot(): StoredSnapshot {
		return new StoredSnapshot($this->config, self::KEY);
	}
}
