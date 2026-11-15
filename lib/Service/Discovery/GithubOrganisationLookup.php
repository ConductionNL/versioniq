<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Discovery;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;

/**
 * Asks GitHub whether an owner is an organisation with a verified domain.
 *
 * @psalm-api
 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
 */
class GithubOrganisationLookup {
	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * GitHub's answer: true or false for an organisation, false for a personal
	 * account (404), null when GitHub could not be asked.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 */
	public function isVerified(string $owner): ?bool {
		$response = $this->request($owner);
		if ($response === null) {
			return null;
		}

		return match ($response->getStatusCode()) {
			404 => false,
			200 => self::verifiedFlag((string)$response->getBody()),
			default => null,
		};
	}

	/**
	 * The organisation request, or null when it could not be sent.
	 */
	private function request(string $owner): ?IResponse {
		try {
			return $this->clientService->newClient()->get(
				'https://api.github.com/orgs/' . rawurlencode($owner),
				[
					'headers' => ['Accept' => 'application/vnd.github+json'],
					'timeout' => 10,
					'http_errors' => false,
				]
			);
		} catch (\Exception $error) {
			$this->logger->info('PublisherTrust: could not ask GitHub about an owner', ['errorMessage' => $error->getMessage()]);

			return null;
		}
	}

	/**
	 * The organisation's `is_verified` flag, or null for a body that is not JSON.
	 */
	private static function verifiedFlag(string $body): ?bool {
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return is_array($decoded) && ($decoded['is_verified'] ?? false) === true;
	}
}
