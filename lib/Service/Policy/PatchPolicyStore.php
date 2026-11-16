<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Policy;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the patch policy in app config key `patch_policy`
 * (autoupdate-security-first D3). A missing or malformed value reads as no
 * policy, so the job keeps today's behaviour; a malformed one is logged.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
 */
class PatchPolicyStore {
	private const JSON_DEPTH = 8;

	public const CONFIG_KEY = 'patch_policy';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The stored policy, or null when none is stored or it cannot be read.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public function read(): ?PatchPolicy {
		$raw = $this->config->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return null;
		}

		try {
			$payload = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
			if (!is_array($payload)) {
				throw new \InvalidArgumentException('patch policy is not an object');
			}

			return PatchPolicy::fromArray($payload);
		} catch (\JsonException|\InvalidArgumentException $error) {
			$this->logger->warning('PatchPolicyStore: malformed patch policy, treating as none', ['message' => $error->getMessage()]);

			return null;
		}
	}

	/**
	 * The stored policy, or the no-policy reading.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public function effective(): PatchPolicy {
		return $this->read() ?? PatchPolicy::none();
	}

	/**
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public function write(PatchPolicy $policy): void {
		$this->config->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode($policy->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		);
	}
}
