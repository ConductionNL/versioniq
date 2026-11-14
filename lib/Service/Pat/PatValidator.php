<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Pat;

use Exception;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Service\Source\Forge;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Probes a PAT against `GET https://api.github.com/user` to:
 *   - confirm the token is valid (non-401)
 *   - read scope from the `X-OAuth-Scopes` response header (classic only)
 *   - read expiry from the `github-authentication-token-expiration` header
 *
 * Classic PAT (`ghp_*`) scope check is hard — any scope outside
 * `repo` / `public_repo` rejects the upload. Fine-grained PATs
 * (`github_pat_*`) do not expose configured permissions to the holder, so
 * we accept them with an explicit `unverifiable_scope` warning.
 *
 * @psalm-api
 */
class PatValidator {
	private const USER_AGENT = 'Nextcloud-Versioniq';

	/** @var list<string> */
	public const ALLOWED_CLASSIC_SCOPES = ['repo', 'public_repo'];

	/**
	 * The GitLab token scopes that only read (sources-gitlab-forge D5).
	 */
	public const ALLOWED_GITLAB_SCOPES = ['read_api', 'read_repository'];

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private ForgeRegistry $forgeRegistry,
		private IConfig $config,
	) {
	}

	/**
	 * Classifies a token as classic vs fine-grained by prefix; see "PAT validation on upload".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function detectKind(string $token): string {
		if (str_starts_with($token, 'ghp_')) {
			return Pat::KIND_CLASSIC;
		}
		if (str_starts_with($token, 'github_pat_')) {
			return Pat::KIND_FINE_GRAINED;
		}

		return Pat::KIND_FINE_GRAINED; // Conservative default; pure user-supplied strings get the safer code path.
	}

	/**
	 * Probes the token against GitHub's user endpoint and enforces least-privilege scope; see "PAT validation on upload".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function validate(
		#[\SensitiveParameter]
		string $token,
		string $forge = ForgeRegistry::FORGE_GITHUB,
	): ValidationResult {
		// Fail closed on an unknown forge: ForgeRegistry::get() throws
		// InvalidArgumentException, which the OCS base controller does not catch
		// and would surface as an HTTP 500. Reject in-band instead (-> HTTP 400).
		if (!$this->forgeRegistry->has($forge)) {
			return ValidationResult::rejected(sprintf('Unknown forge "%s".', $forge));
		}
		$f = $this->forgeRegistry->get($forge);
		$kind = $this->detectKind($token);

		// GitLab describes the token itself: probe that, so its scopes and
		// expiry are read rather than guessed (sources-gitlab-forge D5).
		$tokenInfo = $f->tokenInfoEndpoint();
		$response = $this->probe($f, $token, $tokenInfo ?? $f->userEndpoint());
		if ($response instanceof ValidationResult) {
			return $response;
		}

		$rejection = $this->statusRejection($f, $response->getStatusCode());
		if ($rejection !== null) {
			return $rejection;
		}

		if ($tokenInfo !== null) {
			return $this->tokenInfoResult((string)$response->getBody());
		}

		// Forges that do not expose token scopes to the holder (e.g.
		// Codeberg/Forgejo, GitHub fine-grained PATs) are accepted best-effort
		// with an explicit warning to verify least privilege manually.
		if (!$f->exposesScopeHeader) {
			return ValidationResult::accepted(
				[],
				[sprintf('unverifiable_scope: %s does not expose token permissions to the holder; please verify the token is read-only (repository contents read access only).', ucfirst($f->id))],
				null,
			);
		}

		return $this->scopeResult($kind, $response->getHeaders());
	}

	/**
	 * Calls the forge's user endpoint with the token; a rejection when the forge cannot be reached.
	 */
	private function probe(Forge $f, string $token, string $endpoint): IResponse|ValidationResult {
		$client = $this->clientService->newClient();

		try {
			return $client->get($endpoint, [
				'headers' => $this->probeHeaders($f, $token),
				'timeout' => 30,
				// IClient (Guzzle) throws on 4xx by default; we want to inspect
				// the status ourselves so we can produce a useful error message.
				'http_errors' => false,
				'nextcloud' => ['allow_local_address' => $this->config->getSystemValueBool('allow_local_remote_servers', false)],
			]);
		} catch (Exception $error) {
			$this->logger->warning('PatValidator: probe failed', ['forge' => $f->id, 'errorMessage' => $error->getMessage()]);

			return ValidationResult::rejected(sprintf('Could not reach %s — check network connectivity.', $this->hostLabel($f)));
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function probeHeaders(Forge $f, string $token): array {
		$headers = [
			'Authorization' => $f->authHeaderValue($token),
			'Accept' => 'application/json',
			'User-Agent' => self::USER_AGENT,
		];
		if ($f->id === ForgeRegistry::FORGE_GITHUB) {
			$headers['Accept'] = 'application/vnd.github+json';
			$headers['X-GitHub-Api-Version'] = '2022-11-28';
		}

		return $headers;
	}

	/**
	 * GitLab's own description of the token: accepted only when every scope
	 * is read-only (`read_api`, `read_repository`), refused when it can write
	 * or impersonate, with its expiry captured (design D5).
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-3.1
	 */
	private function tokenInfoResult(string $body): ValidationResult {
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ValidationResult::rejected('GitLab did not describe the token.');
		}

		$info = is_array($decoded) ? $decoded : [];
		/** @var mixed $rawScopes */
		$rawScopes = $info['scopes'] ?? null;
		$scopes = is_array($rawScopes) ? array_values(array_filter($rawScopes, static fn (mixed $scope): bool => is_string($scope))) : [];

		if ($scopes === []) {
			return ValidationResult::rejected('GitLab reports no scopes for this token; create one with read_api.');
		}

		$disallowed = array_values(array_diff($scopes, self::ALLOWED_GITLAB_SCOPES));
		if ($disallowed !== []) {
			return ValidationResult::rejected(sprintf('The token has write or admin scopes (%s); create one with only read_api.', implode(', ', $disallowed)));
		}

		/** @var mixed $rawExpires */
		$rawExpires = $info['expires_at'] ?? null;
		$expires = is_string($rawExpires) ? $rawExpires : null;

		return ValidationResult::accepted($scopes, [], $this->parseExpires($expires));
	}

	private function hostLabel(Forge $f): string {
		$host = parse_url($f->apiBaseUrl, PHP_URL_HOST);

		return is_string($host) ? $host : $f->id;
	}

	/**
	 * The rejection for a probe status other than 200, else null.
	 */
	private function statusRejection(Forge $f, int $status): ?ValidationResult {
		if ($status === 401) {
			return ValidationResult::rejected('Token is invalid or revoked.');
		}
		if ($status === 403) {
			return ValidationResult::rejected('The rate limit was exceeded — try again later.');
		}
		if ($status !== 200) {
			return ValidationResult::rejected(sprintf('%s returned HTTP %d.', $this->hostLabel($f), $status));
		}

		return null;
	}

	/**
	 * Enforces least privilege from the scope header for a classic token; a
	 * fine-grained token is accepted with a warning, as GitHub does not expose
	 * its permissions.
	 *
	 * @param array<array-key, mixed> $headers
	 */
	private function scopeResult(string $kind, array $headers): ValidationResult {
		$scopesHeader = $this->headerValue($headers, 'X-OAuth-Scopes');
		$expiresAt = $this->parseExpires($this->headerValue($headers, 'github-authentication-token-expiration'));

		if ($kind === Pat::KIND_CLASSIC) {
			return $this->classicScopeResult($this->parseScopes($scopesHeader), $expiresAt);
		}

		return ValidationResult::accepted(
			[],
			['unverifiable_scope: GitHub did not expose configured permissions; please verify they are read-only (Contents: Read-only, Metadata: Read-only).'],
			$expiresAt
		);
	}

	/**
	 * @param list<string> $scopes
	 */
	private function classicScopeResult(array $scopes, ?string $expiresAt): ValidationResult {
		$disallowed = array_values(array_filter(
			$scopes,
			static fn (string $scope): bool => !in_array($scope, self::ALLOWED_CLASSIC_SCOPES, true)
		));
		if ($disallowed !== []) {
			return ValidationResult::rejected(sprintf(
				'PAT has scopes beyond what Versioniq needs (%s). Recreate with %s only.',
				implode(', ', $disallowed),
				implode(' or ', self::ALLOWED_CLASSIC_SCOPES)
			));
		}

		return ValidationResult::accepted($scopes, [], $expiresAt);
	}

	/**
	 * @param array<array-key, mixed> $headers
	 */
	private function headerValue(array $headers, string $name): ?string {
		/** @var mixed $values */
		foreach ($headers as $key => $values) {
			if (strcasecmp((string)$key, $name) !== 0) {
				continue;
			}
			if (is_array($values)) {
				/** @var mixed $first */
				$first = $values[0] ?? null;

				return is_string($first) ? $first : null;
			}
			if (is_string($values)) {
				return $values;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private function parseScopes(?string $headerValue): array {
		if ($headerValue === null || trim($headerValue) === '') {
			return [];
		}

		$out = [];
		foreach (explode(',', $headerValue) as $scope) {
			$trimmed = trim($scope);
			if ($trimmed !== '') {
				$out[] = $trimmed;
			}
		}

		return $out;
	}

	private function parseExpires(?string $value): ?string {
		if ($value === null || trim($value) === '') {
			return null;
		}

		try {
			return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
		} catch (Exception) {
			return null;
		}
	}
}
