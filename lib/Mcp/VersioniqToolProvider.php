<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Mcp;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\InstallerService;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Versioniq's read-only assistant tools (admin-mcp-assistant).
 *
 * OpenRegister's MCP discovery finds this class by name
 * (`OCA\{App}\Mcp\{App}ToolProvider`), so nothing registers it and it only
 * loads where OpenRegister and its interface exist (design D1). Five tools
 * answer what is installed, what is affected and what to move to, from the
 * same stored snapshots and services the admin page reads (D2). Versioniq is
 * admin-only, so the tools are too: a non-admin is offered none and every
 * call checks the caller first, before anything is read (D3). No tool
 * installs, pins or changes anything.
 *
 * @spec openspec/changes/admin-mcp-assistant/specs/assistant-tools/spec.md
 */
class VersioniqToolProvider implements IMcpToolProvider {

	/**
	 * The answer to a caller who is not an admin.
	 */
	public const NOT_ADMIN = 'Versioniq is available to administrators only.';

	/**
	 * The hints every tool carries: it reads, changes nothing and can be repeated.
	 */
	private const READ_ONLY = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true];

	/**
	 * Constructor.
	 *
	 * @param InstallerService $installer The installed apps and an app's versions.
	 * @param AdvisoryResultStore $advisories The stored advisory results.
	 * @param AvailabilityResultStore $availability The stored pending-updates snapshot.
	 * @param IUserSession $userSession The caller.
	 * @param IGroupManager $groupManager Whether the caller is an admin.
	 */
	public function __construct(
		private InstallerService $installer,
		private AdvisoryResultStore $advisories,
		private AvailabilityResultStore $availability,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * The app that owns these tools.
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.1
	 */
	public function getAppId(): string {
		return 'versioniq';
	}

	/**
	 * The five tool descriptors, or none for a caller who is not an admin.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.1
	 */
	public function getTools(): array {
		if (!$this->callerIsAdmin()) {
			return [];
		}

		$none = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
		$byApp = [
			'type' => 'object',
			'properties' => ['appId' => ['type' => 'string', 'description' => 'The app id, for example "deck".']],
			'required' => ['appId'],
			'additionalProperties' => false,
		];

		return [
			self::tool('versioniq.listApps', 'List apps', 'The apps Versioniq manages with their name, installed version, state and bound source.', $none),
			self::tool('versioniq.listAdvisories', 'List advisories', 'The stored security advisory results per app and when they were checked, optionally only one severity.', [
				'type' => 'object',
				'properties' => ['severity' => ['type' => 'string', 'description' => 'Only advisories of this severity, for example "high".']],
				'additionalProperties' => false,
			]),
			self::tool('versioniq.explainAdvisory', 'Explain advisories', "One app's advisories with the recommended version to move to, and why there is none when there is none.", $byApp),
			self::tool('versioniq.listPendingUpdates', 'List pending updates', 'The stored snapshot of apps with a newer version available, and when it was taken.', $none),
			self::tool('versioniq.listVersions', 'List versions', "One app's available versions from its bound source, with server compatibility.", $byApp),
		];
	}

	/**
	 * Run one tool for the caller, after checking they are an admin.
	 *
	 * @param string $toolId The tool id.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.2
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		if (!$this->callerIsAdmin()) {
			return ['error' => self::NOT_ADMIN];
		}

		return match ($toolId) {
			'versioniq.listApps' => $this->listApps(),
			'versioniq.listAdvisories' => $this->listAdvisories(severity: self::stringArgument($arguments, 'severity')),
			'versioniq.explainAdvisory' => $this->withAppId($arguments, fn (string $appId): array => $this->explainAdvisory($appId)),
			'versioniq.listPendingUpdates' => $this->availability->read(),
			'versioniq.listVersions' => $this->withAppId($arguments, fn (string $appId): array => $this->listVersions($appId)),
			default => ['error' => 'Unknown tool: ' . $toolId],
		};
	}

	/**
	 * The managed apps, trimmed to what an assistant needs.
	 *
	 * @return array{apps: list<array{id: string, name: string, installedVersion: ?string, state: string, source: ?string}>}
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.3
	 */
	private function listApps(): array {
		$apps = [];
		foreach ($this->installer->getInstalledApps() as $app) {
			$apps[] = [
				'id' => (string)($app['id'] ?? ''),
				'name' => (string)($app['label'] ?? ($app['id'] ?? '')),
				'installedVersion' => isset($app['installedVersion']) ? (string)$app['installedVersion'] : null,
				'state' => (string)($app['state'] ?? ''),
				'source' => isset($app['boundSourceId']) ? (string)$app['boundSourceId'] : null,
			];
		}

		return ['apps' => $apps];
	}

	/**
	 * The stored advisory results, optionally only one severity; an app left
	 * with no advisory of that severity is left out.
	 *
	 * @param string|null $severity The severity, or null for all.
	 *
	 * @return array{advisories: array<string, mixed>, checkedAt: mixed}
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.3
	 */
	private function listAdvisories(?string $severity): array {
		$stored = $this->advisories->read();
		if ($severity === null) {
			return $stored;
		}

		$wanted = strtolower($severity);
		$kept = [];
		foreach ($stored['advisories'] as $key => $result) {
			$matching = array_values(array_filter(
				(array)($result['advisories'] ?? []),
				static fn (mixed $advisory): bool => is_array($advisory) && strtolower((string)($advisory['severity'] ?? '')) === $wanted
			));
			if ($matching !== []) {
				$kept[$key] = array_replace((array)$result, ['advisories' => $matching]);
			}
		}

		return ['advisories' => $kept, 'checkedAt' => $stored['checkedAt']];
	}

	/**
	 * One app's advisories, the version to move to, and why there is none
	 * when there is none.
	 *
	 * @param string $appId The app.
	 *
	 * @return array{appId: string, checkedAt: mixed, installedVersion: ?string, state: ?string, advisories: list<mixed>, recommendedVersion: ?string, reason: ?string}
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.3
	 */
	private function explainAdvisory(string $appId): array {
		$stored = $this->advisories->read();
		$result = $stored['advisories'][$appId] ?? null;
		if (!is_array($result)) {
			return [
				'appId' => $appId,
				'checkedAt' => $stored['checkedAt'],
				'installedVersion' => null,
				'state' => null,
				'advisories' => [],
				'recommendedVersion' => null,
				'reason' => 'There is no stored advisory result for this app; the advisory check has not covered it yet.',
			];
		}

		$recommended = isset($result['recommendedVersion']) ? (string)$result['recommendedVersion'] : null;

		return [
			'appId' => $appId,
			'checkedAt' => $stored['checkedAt'],
			'installedVersion' => isset($result['installedVersion']) ? (string)$result['installedVersion'] : null,
			'state' => isset($result['state']) ? (string)$result['state'] : null,
			'advisories' => array_values((array)($result['advisories'] ?? [])),
			'recommendedVersion' => $recommended,
			'reason' => $recommended === null ? self::noRecommendationReason($result) : null,
		];
	}

	/**
	 * Why an advisory result names no version to move to.
	 *
	 * @param array<string, mixed> $result The stored result for one app.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.3
	 */
	private static function noRecommendationReason(array $result): string {
		$error = $result['error'] ?? null;
		if (is_string($error) && $error !== '') {
			return 'The advisory check could not finish for this app: ' . $error;
		}

		return match ($result['state'] ?? null) {
			AdvisoryService::STATE_NONE => 'No published advisory concerns this app.',
			AdvisoryService::STATE_AVAILABLE => 'The installed version is not affected by the published advisories, so there is nothing to move to.',
			default => 'No available version resolves these advisories yet.',
		};
	}

	/**
	 * One app's available versions, trimmed to what an assistant needs.
	 *
	 * @param string $appId The app.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.3
	 */
	private function listVersions(string $appId): array {
		$listing = $this->installer->getAppVersions($appId);
		$versions = [];
		foreach ((array)($listing['versions'] ?? []) as $version) {
			if (!is_array($version)) {
				continue;
			}

			$versions[] = [
				'version' => (string)($version['version'] ?? ''),
				'serverCompatible' => $version['serverCompatible'] ?? null,
				'releasedAt' => $version['releasedAt'] ?? null,
			];
		}

		$answer = [
			'appId' => $appId,
			'installedVersion' => $listing['installedVersion'] ?? null,
			'source' => $listing['source'] ?? null,
			'versions' => $versions,
		];
		if (isset($listing['error']) && is_string($listing['error'])) {
			$answer['error'] = $listing['error'];
		}

		return $answer;
	}

	/**
	 * Run a per-app tool, or refuse when no app id is given.
	 *
	 * @param array<string, mixed> $arguments The arguments.
	 * @param callable(string): array<string, mixed> $tool The tool, given the app id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.3
	 */
	private function withAppId(array $arguments, callable $tool): array {
		$appId = self::stringArgument($arguments, 'appId');
		if ($appId === null) {
			return ['error' => 'appId is required.'];
		}

		return $tool($appId);
	}

	/**
	 * A non-empty string argument, trimmed, or null.
	 *
	 * @param array<string, mixed> $arguments The arguments.
	 * @param string $name The argument.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.3
	 */
	private static function stringArgument(array $arguments, string $name): ?string {
		$value = $arguments[$name] ?? null;
		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		return trim($value);
	}

	/**
	 * Whether the session user is an admin.
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.2
	 */
	private function callerIsAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}

	/**
	 * One read-only tool descriptor.
	 *
	 * @param string $id The tool id.
	 * @param string $name The tool name.
	 * @param string $description What it answers.
	 * @param array<string, mixed> $inputSchema Its input.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/admin-mcp-assistant/tasks.md#task-1.1
	 */
	private static function tool(string $id, string $name, string $description, array $inputSchema): array {
		return ['id' => $id, 'name' => $name, 'description' => $description, 'inputSchema' => $inputSchema] + self::READ_ONLY;
	}
}
