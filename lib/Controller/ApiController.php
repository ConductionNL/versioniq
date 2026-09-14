<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Controller;

use InvalidArgumentException;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatValidator;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\ServerVersion;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	/**
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private InstallerService $installerService,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ServerVersion $serverVersion,
		private PatMapper $patMapper,
		private PatManager $patManager,
		private PatValidator $patValidator,
		private PatDeeplinkBuilder $deeplinkBuilder,
		private PatExpiryEvaluator $patExpiryEvaluator,
		private DiscoveryAggregator $discoveryAggregator,
		private AdvisoryResultStore $advisoryResultStore,
		private AdvisorySettingsStore $advisorySettingsStore,
		private AuditEntryMapper $auditEntryMapper,
		private PinStore $pinStore,
		private IAppManager $appManager,
		private ITimeFactory $timeFactory,
		private PolicyStore $policyStore,
		private AutoUpdateSettingsStore $autoUpdateSettingsStore,
		private ArtifactCache $artifactCache,
		// Asks integriq to look again after a token save or removal
		// (adopt-connection-registry). Optional and last, so every existing
		// caller and test keeps working.
		private ?ConnectionReportService $connectionReports = null,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Reports whether the current user is an admin so the frontend can gate the UI
	 *
	 * @return DataResponse<Http::STATUS_OK, array{isAdmin: bool}, array{}>
	 *
	 * 200: Admin status returned
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/admin-check')]
	public function adminCheck(): DataResponse {
		return new DataResponse(['isAdmin' => $this->isAdmin()], Http::STATUS_OK);
	}

	/**
	 * Lists installed apps (admin-only); see "List Installed Apps"
	 *
	 * @return DataResponse<Http::STATUS_OK, array{apps: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Installed apps returned
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/apps')]
	public function apps(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['apps' => $this->installerService->getInstalledApps()]);
	}

	/**
	 * Returns the most recent security-advisory correlation for each installed
	 * app (admin-only, read-only): a per-app map of advisory state
	 * (`none` | `advisory-available` | `pinned-to-vulnerable`), the matching
	 * advisories, and the recommended safe version. Never changes a version.
	 *
	 * READS A SNAPSHOT, DOES NOT COMPUTE ONE. Correlation costs two external
	 * calls per app — ~176 sequential calls on an 88-app instance — which this
	 * endpoint used to do inline. Measured on a live instance it then did not
	 * answer within 120s, twice, and while it held the PHP session lock the
	 * sibling `/api/pins` request never ran at all, so pin badges silently
	 * never rendered (issue #160). The sweep now runs in AdvisoryRefreshJob
	 * every 6 hours and this endpoint serves what it stored.
	 *
	 * `checkedAt` is part of the contract, not decoration: it is the unix time
	 * of the last completed sweep, and `null` means no sweep has completed
	 * yet. Without it the client cannot tell a fresh "no advisories" from a
	 * six-hour-old one, or from an instance whose cron has never run — three
	 * states that otherwise render as an identical empty map.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{advisories: array<string, mixed>, checkedAt: ?int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Stored advisory correlation returned
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/advisories')]
	public function advisories(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$snapshot = $this->advisoryResultStore->read();

		return new DataResponse([
			'advisories' => $snapshot['advisories'],
			'checkedAt' => $snapshot['checkedAt'],
		]);
	}

	/**
	 * Returns the server update channel so versions can be filtered; see "Respect update channel"
	 *
	 * @return DataResponse<Http::STATUS_OK, array{updateChannel: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Update channel returned
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/update-channel')]
	public function updateChannel(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'updateChannel' => $this->serverVersion->getChannel(),
		]);
	}

	/**
	 * Lists registered sources and trusted-source globs; see "Source management API"
	 *
	 * @return DataResponse<Http::STATUS_OK, array{sources: list<array<string, mixed>>, trustedPatterns: list<string>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Sources and trusted patterns returned
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/sources')]
	public function sources(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'sources' => $this->installerService->getSourceRegistry()->listAvailable(),
			'trustedPatterns' => $this->installerService->getTrustedSources()->getPatterns(),
		]);
	}

	/**
	 * Returns the active source binding for an app, including any recorded
	 * SHA-256 digests (not secrets); see "Source binding" and "Recorded
	 * digests are binding-scoped and surfaced"
	 *
	 * @param string $appId The app to read the binding for
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string, binding: array<string, mixed>|null, sourceId: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Binding returned; `binding` is null when the app has none
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/source/{appId}/binding')]
	public function getBinding(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$binding = $this->installerService->getBinding($appId);

		return new DataResponse([
			'appId' => $appId,
			'binding' => $binding?->toArray(),
			'sourceId' => $binding?->getId() ?? 'appstore',
		]);
	}

	/**
	 * Binds a source to an app after allowlist validation; see "Source management API"
	 *
	 * @param string $appId The app to bind a source to
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string, sourceId: string, binding: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Source bound; the persisted binding is returned
	 * 400: The requested source kind or its arguments are invalid
	 * 403: Caller is not an administrator, or the source is not allowlisted
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/source/{appId}/bind')]
	public function bindSource(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$kind = $this->stringParam('kind', '');
		$forge = $this->stringParam('forge', SourceBinding::FORGE_GITHUB);
		try {
			$binding = match ($kind) {
				SourceBinding::KIND_APPSTORE => SourceBinding::appStore(),
				SourceBinding::KIND_GITHUB_RELEASE => $forge === SourceBinding::FORGE_CODEBERG
					? SourceBinding::codeberg(
						$this->stringParam('owner', ''),
						$this->stringParam('repo', ''),
						$this->stringParam('assetPattern', '*.tar.gz'),
					)
					: SourceBinding::github(
						$this->stringParam('owner', ''),
						$this->stringParam('repo', ''),
						$this->stringParam('assetPattern', '*.tar.gz'),
					),
				default => throw new InvalidArgumentException('Unknown source kind: ' . $kind),
			};
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->installerService->bindSource($appId, $binding);
		} catch (UntrustedSourceException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_FORBIDDEN);
		}

		// Re-read the persisted binding: rebinding to the same source id
		// preserves any previously recorded SHA-256 digests, so the response
		// should reflect what was actually written, not the pre-write value —
		// see "Recorded digests are binding-scoped and surfaced".
		$persisted = $this->installerService->getBinding($appId);

		return new DataResponse([
			'appId' => $appId,
			'sourceId' => $binding->getId(),
			'binding' => ($persisted ?? $binding)->toArray(),
		]);
	}

	/**
	 * Curated add of a forge-qualified trusted-source pattern; see "Source management API".
	 *
	 * Admin access is enforced by the runtime isAdmin() guard below (covered by
	 * both the 403 and 200 controller tests). The declarative
	 * #[AuthorizedAdminSetting] attribute is intentionally not used here: it
	 * requires a class-string<IDelegatedSettings>, whereas Settings\Admin is a
	 * plain ISettings — adopting it would also opt this endpoint into admin
	 * delegation semantics, a product change to make deliberately.
	 *
	 * @param string|null $repo Restrict the pattern to a single repository; omit or leave blank to trust the whole owner
	 *
	 * @return DataResponse<Http::STATUS_OK, array{trustedPatterns: list<string>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: The full trusted-pattern list after the addition
	 * 403: Caller is not an administrator
	 * 422: The pattern is not forge-qualified or is otherwise unacceptable
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/trusted-sources')]
	public function addTrustedSource(?string $repo = null): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$forge = $this->stringParam('forge', '');
		$owner = $this->stringParam('owner', '');
		// Declared as a method parameter rather than read via getParam(): the
		// two resolve from the same merged request parameters, but only a
		// declared parameter can carry a type and a description into the
		// generated OpenAPI spec. The null-vs-empty distinction below is
		// unchanged — an omitted `repo` and a blank one both mean "no repo".
		$repo = ($repo !== null && trim($repo) !== '') ? trim($repo) : null;

		try {
			$patterns = $this->installerService->addTrustedPattern($forge, $owner, $repo);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['trustedPatterns' => $patterns]);
	}

	/**
	 * Removes a trusted-source pattern. The pattern is passed as a `pattern`
	 * query parameter (not a path segment) because patterns contain `/`, and
	 * Apache rejects encoded slashes in the path (`AllowEncodedSlashes Off`) with
	 * a 404 before the request reaches Nextcloud; query strings carry `%2F` fine.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{trustedPatterns: list<string>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The full trusted-pattern list after the removal
	 * 400: No `pattern` query parameter was supplied
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/trusted-sources')]
	public function removeTrustedSource(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$pattern = $this->stringParam('pattern', '');
		if ($pattern === '') {
			return new DataResponse(['message' => 'A pattern is required.'], Http::STATUS_BAD_REQUEST);
		}

		$patterns = $this->installerService->removeTrustedPattern($pattern);

		return new DataResponse(['trustedPatterns' => $patterns]);
	}

	/**
	 * Fetches available versions from the bound (or overridden) source; see "Fetch Available Versions"
	 * and "Version listings carry release notes"
	 *
	 * @param string $appId The app to list versions for
	 * @param string|null $source Override the bound source for this lookup only; omit to use the app's binding
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Version listing from the bound source. The status is whatever the
	 *      source reported, mapped through toHttpStatus()
	 * 403: Caller is not an administrator
	 *
	 * The @return above is the DOCUMENTED contract — the two outcomes a caller
	 * designs against. The runtime status is a passthrough: toHttpStatus()
	 * returns any code on its whitelist, so psalm widens the inferred type to
	 * all ~60 of them and cannot reconcile it with 200|403. Widening the
	 * annotation to match would make the generated OpenAPI spec describe sixty
	 * responses and document nothing, so the mismatch is suppressed here, at
	 * the one method where it is true, rather than the annotation being made
	 * useless.
	 *
	 * @psalm-suppress InvalidReturnType
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/changelog-visibility/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/app/{appId}/versions')]
	public function appVersions(string $appId, ?string $source = null): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$sourceOverride = ($source !== null && trim($source) !== '') ? trim($source) : null;

		$result = $this->installerService->getAppVersions($appId, $sourceOverride);
		$statusCode = $result['statusCode'] ?? Http::STATUS_OK;
		unset($result['statusCode'], $result['hasError']);

		/** @psalm-suppress InvalidReturnStatement Dynamic status passthrough — see the docblock. */
		return new DataResponse($result, $this->toHttpStatus($statusCode, Http::STATUS_OK));
	}

	/**
	 * Installs a specific version (password-confirmed); see "Install Specific
	 * Version" and, when the app is pinned, "Pins are enforced on App
	 * Versions' own install path" (`overridePin=repin|unpin`, `pin=1`). For an
	 * external source with a recorded SHA-256 mismatch, `acceptNewSha=1`
	 * bypasses the check once and replaces the recorded digest on success; see
	 * "Recorded SHA-256 enforced on reinstall". A downgrade (target version
	 * older than installed) is refused with a 409 unless `allowDowngrade=1`;
	 * see "Server-side downgrade guard".
	 *
	 * `dryRun` is an independent boolean, decoupled from `debug`; see
	 * MODIFIED "Debug Mode". When `dryRun` is not supplied at all, `debug=1`
	 * still implies a dry run (deprecated back-compat) and the response
	 * carries a `deprecationNotice`.
	 *
	 * @param string $appId The app to install a version of
	 * @param string $version The target version, overridable by a `targetVersion` or `version` body parameter
	 * @param string|null $source Override the bound source for this install only
	 * @param string $debug `1` to include debug output; on its own it still implies a dry run (deprecated)
	 * @param string|null $dryRun `1` to simulate, `0` to install for real. OMITTED is distinct from `0`: only when omitted does `debug=1` still imply a dry run
	 * @param string $pin `1` to pin the app to this version after installing
	 * @param string $acceptNewSha `1` to accept a changed SHA-256 once and replace the recorded digest
	 * @param string $allowDowngrade `1` to permit installing a version older than the installed one
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_INTERNAL_SERVER_ERROR, array<string, mixed>, array{}>
	 *
	 * 200: The installer's own payload. The status is whatever the installer
	 *      reported, mapped through toHttpStatus() — a refused downgrade is a
	 *      409 and a SHA-256 mismatch a 4xx, both carried in that payload
	 * 403: Caller is not an administrator
	 * 500: The installer reported no usable status code
	 *
	 * As with appVersions(), the @return above is the documented contract; the
	 * runtime status is a toHttpStatus() passthrough psalm cannot narrow.
	 *
	 * @psalm-suppress InvalidReturnType
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/migration-safety/spec.md
	 * @spec openspec/specs/cli-commands/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/app/{appId}/versions/{version}/install')]
	public function installVersion(
		string $appId,
		string $version,
		?string $source = null,
		string $debug = '0',
		?string $dryRun = null,
		string $pin = '0',
		string $acceptNewSha = '0',
		string $allowDowngrade = '0',
	): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$requestedVersion = $this->stringParam('targetVersion', '');
		if ($requestedVersion === '') {
			$requestedVersion = $this->stringParam('version', '');
		}
		if ($requestedVersion === '') {
			$requestedVersion = $version;
		}

		$sourceOverride = ($source !== null && trim($source) !== '') ? trim($source) : null;

		$includeDebug = $this->readBinaryBool($debug, false);

		// `dryRun` is independent of `debug` — see MODIFIED "Debug Mode".
		// THE NULL DEFAULT IS LOAD-BEARING. A `?string` defaulting to null is
		// still null when the caller omitted the parameter entirely, which is
		// how we distinguish "not supplied" (legacy `debug`-implies-dry-run
		// fallback still applies) from an explicit `dryRun=0` (a real install
		// regardless of `debug`). This is exactly what `getParam('dryRun')`
		// with no default used to give us; declaring it as a typed parameter
		// changes the spelling, not the semantics. Give it a '0' default and
		// the legacy fallback silently dies.
		$dryRunSupplied = $dryRun !== null;
		$dryRunFlag = $dryRunSupplied ? $this->readBinaryBool($dryRun, false) : null;

		$overridePinRaw = $this->stringParam('overridePin', '');
		$overridePin = $overridePinRaw === '' ? null : $overridePinRaw;
		$pinRequested = $this->readBinaryBool($pin, false);
		$acceptNewShaFlag = $this->readBinaryBool($acceptNewSha, false);
		$allowDowngradeFlag = $this->readBinaryBool($allowDowngrade, false);

		$result = $this->installerService->installAppVersion(
			$appId,
			$requestedVersion,
			$includeDebug,
			$sourceOverride,
			$overridePin,
			$pinRequested,
			$acceptNewShaFlag,
			$allowDowngradeFlag,
			$dryRunFlag,
		);
		$result['payload']['requestedVersion'] = $requestedVersion;
		$result['payload']['routeVersion'] = $version;
		if (!$dryRunSupplied && $includeDebug) {
			// Legacy back-compat path only — see MODIFIED "Debug Mode",
			// Scenario "Legacy behavior preserved".
			$result['payload']['deprecationNotice'] = 'debug=1 implying a dry run is deprecated; pass dryRun=1 explicitly instead.';
		}

		/** @psalm-suppress InvalidReturnStatement Dynamic status passthrough — see the docblock. */
		return new DataResponse(
			$result['payload'] ?? [],
			$this->toHttpStatus($result['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR, Http::STATUS_INTERNAL_SERVER_ERROR)
		);
	}

	/**
	 * Lists all pins joined with the live installed version and current
	 * drift status; see "Honest pin presentation"
	 *
	 * @return DataResponse<Http::STATUS_OK, array{pins: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Pins returned, each joined with its live installed version
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/pins')]
	public function pins(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$pins = [];
		foreach ($this->pinStore->all() as $appId => $pin) {
			try {
				$installed = $this->appManager->getAppVersion($appId, false);
				$installedVersion = $installed !== '' ? $installed : null;
			} catch (\Exception) {
				$installedVersion = null;
			}

			$pins[] = $pin->toArray() + [
				'appId' => $appId,
				'installedVersion' => $installedVersion,
			];
		}

		return new DataResponse(['pins' => $pins]);
	}

	/**
	 * Pins an app to its currently installed version (password-confirmed);
	 * rejects a `version` other than the installed one; see "Pin an
	 * installed app to its current version"
	 *
	 * @param string $appId The app to pin
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string, pin: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The stored pin
	 * 400: The app is not installed, or a `version` other than the installed one was supplied
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/app/{appId}/pin')]
	public function pinApp(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$installedVersion = $this->appManager->getAppVersion($appId, false);
		} catch (\Exception) {
			$installedVersion = '';
		}
		if ($installedVersion === '') {
			return new DataResponse(['message' => 'App is not installed.'], Http::STATUS_BAD_REQUEST);
		}

		$requestedVersion = $this->stringParam('version', '');
		if ($requestedVersion !== '' && $requestedVersion !== $installedVersion) {
			return new DataResponse(
				['message' => 'Only the installed version can be pinned.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$reason = $this->stringParam('reason', '');

		try {
			$pin = new Pin(
				$installedVersion,
				$user->getUID(),
				$this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
				$reason === '' ? null : $reason,
			);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$this->pinStore->set($appId, $pin);

		return new DataResponse([
			'appId' => $appId,
			'pin' => $pin->toArray(),
		]);
	}

	/**
	 * Removes an app's pin (password-confirmed); the installed version is
	 * unaffected; see "Unpin"
	 *
	 * @param string $appId The app to unpin
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string, unpinned: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The pin was removed, or there was none to remove
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/app/{appId}/pin')]
	public function unpinApp(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->pinStore->clear($appId, $user->getUID());

		return new DataResponse(['appId' => $appId, 'unpinned' => true]);
	}

	/**
	 * Lists every persisted per-app auto-update policy plus the global
	 * kill switch / window; see "Per-app update policy" and "Global kill
	 * switch and window"
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Per-app policies plus the global kill switch and window
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/policies')]
	public function policies(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$policies = [];
		foreach ($this->policyStore->all() as $appId => $policy) {
			$policies[] = $policy->toArray() + ['appId' => $appId];
		}

		return new DataResponse([
			'policies' => $policies,
			'autoUpdateEnabled' => $this->autoUpdateSettingsStore->isEnabled(),
			'autoUpdateWindow' => $this->autoUpdateSettingsStore->getWindow(),
		]);
	}

	/**
	 * Sets an app's auto-update policy level (password-confirmed); rejects an
	 * unknown level with 400; see "Per-app update policy"
	 *
	 * @param string $appId The app to set a policy for
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string, policy: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The stored policy
	 * 400: The requested policy level is not recognised
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/app/{appId}/policy')]
	public function setPolicy(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$level = $this->stringParam('level', '');
		if (!Policy::isValidLevel($level)) {
			return new DataResponse(
				['message' => 'level must be one of: ' . implode(', ', Policy::VALID_LEVELS) . '.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$policy = new Policy(
			$level,
			$user->getUID(),
			$this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
		);
		$this->policyStore->set($appId, $policy);

		return new DataResponse(['appId' => $appId, 'policy' => $policy->toArray()]);
	}

	/**
	 * Clears an app's auto-update policy (password-confirmed); a no-op when
	 * none exists; see "Per-app update policy"
	 *
	 * @param string $appId The app to clear the policy for
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string, cleared: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The policy was cleared, or there was none to clear
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/app/{appId}/policy')]
	public function clearPolicy(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->policyStore->clear($appId);

		return new DataResponse(['appId' => $appId, 'cleared' => true]);
	}

	/**
	 * Updates the global auto-update kill switch and/or maintenance window
	 * (password-confirmed); rejects a malformed window with 400; see "Global
	 * kill switch and window"
	 *
	 * @param string|null $enabled `1`/`0` to set the global kill switch; OMIT to leave it untouched
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The stored global settings
	 * 400: The maintenance window is malformed
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/auto-update/settings')]
	public function updateAutoUpdateSettings(?string $enabled = null): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		// The null default is load-bearing, as it was with getParam() and no
		// default: an omitted `enabled` must leave the kill switch untouched,
		// which is a different thing from `enabled=0` turning it off.
		//
		// AN EMPTY STRING IS AN EXPLICIT FALSE, not "unspecified". A declared
		// string parameter means Nextcloud casts what arrives, and PHP casts a
		// JSON `false` to "" rather than "0". readBinaryBool() answers an
		// unrecognised string with its default — the CURRENT stored value — so
		// without this normalisation, switching the kill switch off returned
		// 200 and changed nothing. The caller now sends '1'/'0', and this is
		// the belt to that braces: any client that sends a bare `false` still
		// gets the behaviour it asked for.
		$enabledParam = ($enabled === '') ? '0' : $enabled;
		$windowParam = $this->stringParam('window', '');

		if ($windowParam !== '' && !AutoUpdateWindow::isValid($windowParam)) {
			return new DataResponse(
				['message' => 'window must be in HH:MM-HH:MM format.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($enabledParam !== null) {
			$this->autoUpdateSettingsStore->setEnabled($this->readBinaryBool($enabledParam, $this->autoUpdateSettingsStore->isEnabled()));
		}
		if ($windowParam !== '') {
			$this->autoUpdateSettingsStore->setWindow($windowParam);
		}

		return new DataResponse([
			'autoUpdateEnabled' => $this->autoUpdateSettingsStore->isEnabled(),
			'autoUpdateWindow' => $this->autoUpdateSettingsStore->getWindow(),
		]);
	}

	/**
	 * Returns the advisory check settings: how often the sweep runs and
	 * whether the weekly digest is sent (admin-only).
	 *
	 * The supported bounds travel WITH the values. A client that has to
	 * hardcode the range in order to build a control will drift from the
	 * server the first time the range changes.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{intervalHours: int, minIntervalHours: int, maxIntervalHours: int, digestEnabled: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Advisory settings returned
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/advisory/settings')]
	public function advisorySettings(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'intervalHours' => $this->advisorySettingsStore->getIntervalHours(),
			'minIntervalHours' => AdvisorySettingsStore::MIN_INTERVAL_HOURS,
			'maxIntervalHours' => AdvisorySettingsStore::MAX_INTERVAL_HOURS,
			'digestEnabled' => $this->advisorySettingsStore->isDigestEnabled(),
		]);
	}

	/**
	 * Updates the advisory check settings (admin-only).
	 *
	 * An out-of-range interval is REJECTED here rather than silently clamped,
	 * because a UI that asks for 48 hours and is answered "200 OK" while the
	 * server stores 24 has been lied to. The store still clamps, for values
	 * that arrive by other routes such as `occ config:app:set`.
	 *
	 * @param ?string $intervalHours How often the sweep runs, in hours. Omitted leaves it unchanged.
	 * @param ?string $digestEnabled Whether the weekly digest is sent ('1'/'0'). Omitted leaves it unchanged.
	 * @return DataResponse<Http::STATUS_OK, array{intervalHours: int, minIntervalHours: int, maxIntervalHours: int, digestEnabled: bool}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Advisory settings updated
	 * 400: intervalHours outside the supported range
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/advisory/settings')]
	#[PasswordConfirmationRequired]
	public function updateAdvisorySettings(?string $intervalHours = null, ?string $digestEnabled = null): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($intervalHours !== null && $intervalHours !== '') {
			if (!is_numeric($intervalHours)) {
				return new DataResponse(
					['message' => 'intervalHours must be a number.'],
					Http::STATUS_BAD_REQUEST
				);
			}
			$hours = (int)$intervalHours;
			if ($hours < AdvisorySettingsStore::MIN_INTERVAL_HOURS || $hours > AdvisorySettingsStore::MAX_INTERVAL_HOURS) {
				return new DataResponse(
					['message' => sprintf(
						'intervalHours must be between %d and %d.',
						AdvisorySettingsStore::MIN_INTERVAL_HOURS,
						AdvisorySettingsStore::MAX_INTERVAL_HOURS,
					)],
					Http::STATUS_BAD_REQUEST
				);
			}
			$this->advisorySettingsStore->setIntervalHours($hours);
		}

		// Same empty-string-is-an-explicit-false handling as the auto-update
		// kill switch above: PHP casts a JSON `false` to "", not "0".
		if ($digestEnabled !== null) {
			$digestParam = ($digestEnabled === '') ? '0' : $digestEnabled;
			$this->advisorySettingsStore->setDigestEnabled(
				$this->readBinaryBool($digestParam, $this->advisorySettingsStore->isDigestEnabled()),
			);
		}

		return new DataResponse([
			'intervalHours' => $this->advisorySettingsStore->getIntervalHours(),
			'minIntervalHours' => AdvisorySettingsStore::MIN_INTERVAL_HOURS,
			'maxIntervalHours' => AdvisorySettingsStore::MAX_INTERVAL_HOURS,
			'digestEnabled' => $this->advisorySettingsStore->isDigestEnabled(),
		]);
	}

	/**
	 * Lists PATs visible to the current admin, redacted, with derived
	 * `expiryState`/`daysRemaining`; see "PAT management API" and
	 * "Expiry state in the PAT API and UI"
	 *
	 * @return DataResponse<Http::STATUS_OK, array{pats: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Redacted PATs with derived expiryState and daysRemaining
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/pats')]
	public function listPats(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$pats = $this->patMapper->findVisibleTo($user->getUID());
		$payload = array_map(
			fn (Pat $pat): array => $this->serializePat($pat),
			$pats
		);

		return new DataResponse(['pats' => $payload]);
	}

	/**
	 * Redacts a PAT and merges in its derived `expiryState`/`daysRemaining`;
	 * see "Expiry state in the PAT API and UI".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @return array<string, mixed>
	 */
	private function serializePat(Pat $pat): array {
		$expiry = $this->patExpiryEvaluator->evaluate($pat->getExpiresAt());

		return $pat->toRedacted() + [
			'expiryState' => $expiry['state'],
			'daysRemaining' => $expiry['daysRemaining'],
		];
	}

	/**
	 * Validates and creates an encrypted PAT; see "PAT validation on upload" and "PAT storage"
	 *
	 * @return DataResponse<Http::STATUS_OK, array{pat: array<string, mixed>, warnings: list<string>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The redacted stored PAT plus any non-fatal validation warnings
	 * 400: The token failed validation against its forge
	 * 403: Caller is not an administrator
	 *
	 * After a GitHub token is stored, integriq is asked to look at the GitHub
	 * connection again, and told the token check reached GitHub. That never
	 * throws and never changes the response.
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/pats')]
	public function createPat(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$label = $this->stringParam('label', '');
		$targetPattern = $this->stringParam('targetPattern', '');
		$token = $this->stringParam('token', '');
		$forge = $this->stringParam('forge', SourceBinding::FORGE_GITHUB);
		if ($label === '' || $targetPattern === '' || $token === '') {
			return new DataResponse(
				['message' => 'label, targetPattern and token are required.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->patValidator->validate($token, $forge);
		if (!$result->ok) {
			return new DataResponse(['message' => $result->error ?? 'PAT validation failed.'], Http::STATUS_BAD_REQUEST);
		}

		// Codeberg/Forgejo tokens are opaque; GitHub tokens are classified by prefix.
		$kind = $forge === SourceBinding::FORGE_CODEBERG
			? Pat::KIND_FORGE_TOKEN
			: $this->patValidator->detectKind($token);

		$pat = $this->patManager->create(
			$user->getUID(),
			$label,
			$kind,
			$targetPattern,
			$token,
			$result->scopes,
			$result->warnings,
			$result->expiresAt,
			$forge,
		);
		$this->connectionReports?->forgeTokenSaved($forge);

		return new DataResponse(['pat' => $this->serializePat($pat), 'warnings' => $result->warnings]);
	}

	/**
	 * Updates a PAT's label / share flag, owner-only; see "PAT management API" and "PAT storage"
	 *
	 * @param int $id The PAT to update
	 * @param string|null $label New label; omit to leave it unchanged
	 * @param string|null $sharedWithAdmins `1`/`0` to change admin sharing; omit to leave it unchanged
	 *
	 * @return DataResponse<Http::STATUS_OK, array{pat: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The updated, redacted PAT
	 * 403: Caller is not an administrator, or is not the PAT's owner
	 * 404: No PAT with that id
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PATCH', url: '/api/pats/{id}')]
	public function patchPat(int $id, ?string $label = null, ?string $sharedWithAdmins = null): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$pat = $this->patMapper->findById($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'PAT not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($pat->getOwnerUid() !== $user->getUID()) {
			return new DataResponse(['message' => 'Only the PAT owner can update it.'], Http::STATUS_FORBIDDEN);
		}

		// Both are nullable so an omitted field leaves that attribute alone —
		// this endpoint is a PATCH, not a replace. Declaring `sharedWithAdmins`
		// as ?string rather than reading it raw drops the former is_bool()
		// branch: Nextcloud coerces a JSON `true` to "1" on the way in, and
		// readBinaryBool() reads "1" as true, so the outcome is unchanged.
		if ($label !== null && trim($label) !== '') {
			$pat->setLabel(trim($label));
		}
		if ($sharedWithAdmins !== null) {
			$pat->setSharedWithAdmins($this->readBinaryBool($sharedWithAdmins, $pat->getSharedWithAdmins()));
		}

		return new DataResponse(['pat' => $this->serializePat($this->patManager->update($pat))]);
	}

	/**
	 * Deletes a PAT, restricted to its owner; see "PAT management API" ("Delete restricted to owner")
	 *
	 * @param int $id The PAT to delete
	 *
	 * @return DataResponse<Http::STATUS_OK, array{deleted: int}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The PAT was deleted
	 * 403: Caller is not an administrator, or is not the PAT's owner
	 * 404: No PAT with that id
	 *
	 * After a GitHub token is removed, integriq is asked to look at the GitHub
	 * connection again.
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/pats/{id}')]
	public function deletePat(int $id): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$pat = $this->patMapper->findById($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'PAT not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($pat->getOwnerUid() !== $user->getUID()) {
			return new DataResponse(['message' => 'Only the PAT owner can delete it.'], Http::STATUS_FORBIDDEN);
		}

		$this->patManager->delete($pat);
		$this->connectionReports?->forgeTokenRemoved($pat->getForge());

		return new DataResponse(['deleted' => $id]);
	}

	/**
	 * Multi-source app search with query-length + filter handling; see "Discovery API"
	 *
	 * @param string $installedOnly `1` to restrict results to apps already installed on this server
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Aggregated results across the enabled discovery sources
	 * 400: The query is too short, or a filter is not recognised
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/discover')]
	public function discover(string $installedOnly = '0'): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$query = $this->stringParam('q', '');
		if (mb_strlen($query) < 2) {
			return new DataResponse(
				['message' => 'Query must be at least 2 characters.'],
				Http::STATUS_BAD_REQUEST
			);
		}
		if (mb_strlen($query) > 100) {
			return new DataResponse(
				['message' => 'Query must be at most 100 characters.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$sourcesParam = $this->stringParam('sources', '');
		$sourceIds = null;
		if ($sourcesParam !== '') {
			$sourceIds = array_values(array_filter(array_map('trim', explode(',', $sourcesParam))));
		}

		// The PARAMETER NAME is the wire contract — it must stay `installedOnly`,
		// which is what the frontend sends; only the local flag is renamed.
		$installedOnlyFlag = $this->readBinaryBool($installedOnly, false);

		$result = $this->discoveryAggregator->search($query, $sourceIds, $installedOnlyFlag);

		return new DataResponse($result);
	}

	/**
	 * Returns a prefilled GitHub PAT-creation deeplink; see "PAT management API" (deeplink scenarios)
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The prefilled deeplink for the requested token kind
	 * 400: The requested token kind is not recognised
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/pats/deeplink')]
	public function patDeeplink(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		// A codeberg forge maps to the opaque forge-token deeplink; otherwise the
		// caller selects a GitHub kind (classic / fine-grained).
		$forge = $this->stringParam('forge', SourceBinding::FORGE_GITHUB);
		$kind = $forge === SourceBinding::FORGE_CODEBERG
			? Pat::KIND_FORGE_TOKEN
			: $this->stringParam('kind', Pat::KIND_FINE_GRAINED);
		try {
			return new DataResponse($this->deeplinkBuilder->build($kind));
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Lists audit entries, newest-first, admin-only, paginated and optionally
	 * filtered by app id; see "Audit entries are immutable and admin-readable".
	 * No mutation endpoint exists for this resource — the retention prune job
	 * is the only deletion path.
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: A page of audit entries, newest first
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/audit')]
	public function auditLog(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$appId = $this->stringParam('appId', '');
		$limit = $this->intParam('limit', 50);
		$limit = max(1, min($limit, 200));
		$offset = max(0, $this->intParam('offset', 0));

		$entries = $this->auditEntryMapper->findPage($appId !== '' ? $appId : null, $limit, $offset);

		return new DataResponse([
			'entries' => $entries,
			'limit' => $limit,
			'offset' => $offset,
		]);
	}

	/**
	 * Cache summary: per-app cached versions + size, and the total cache size,
	 * admin-only; see "Cache visibility and management"
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Per-app cached versions with sizes, plus the total cache size
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/cache')]
	public function cacheSummary(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse($this->artifactCache->summary());
	}

	/**
	 * Clears cached release artifacts — all apps, or a single app when
	 * `appId` is supplied — password-confirmed; see "Cache visibility and
	 * management" ("Clear cache")
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The cache summary as it stands after the clear
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/cache')]
	public function clearCache(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$appId = $this->stringParam('appId', '');
		$this->artifactCache->clear($appId !== '' ? $appId : null);

		return new DataResponse($this->artifactCache->summary());
	}

	private function intParam(string $name, int $default): int {
		/** @var mixed $value */
		$value = $this->request->getParam($name, (string)$default);
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && trim($value) !== '' && preg_match('/^-?\d+$/', trim($value))) {
			return (int)trim($value);
		}

		return $default;
	}

	private function stringParam(string $name, string $default): string {
		/** @var mixed $value */
		$value = $this->request->getParam($name, $default);

		return is_string($value) ? trim($value) : $default;
	}

	/**
	 * Coerces a service-provided integer status code into a valid HTTP status,
	 * falling back to $fallback when the value is outside the known set.
	 *
	 * @param int $status
	 * @param 100|101|102|200|201|202|203|204|205|206|207|208|226|300|301|302|303|304|305|306|307|400|401|402|403|404|405|406|407|408|409|410|411|412|413|414|415|416|417|418|422|423|424|426|428|429|431|500|501|502|503|504|505|506|507|508|509|510|511 $fallback
	 * @return 100|101|102|200|201|202|203|204|205|206|207|208|226|300|301|302|303|304|305|306|307|400|401|402|403|404|405|406|407|408|409|410|411|412|413|414|415|416|417|418|422|423|424|426|428|429|431|500|501|502|503|504|505|506|507|508|509|510|511
	 */
	private function toHttpStatus(int $status, int $fallback): int {
		// Install failures are now classified to category-appropriate statuses by
		// FailureClassifier: 409 (preflight_permission), 422 (incompatible /
		// version/appId/checksum mismatch) and 502 (download). These are present
		// in the whitelist below intentionally — do not remove them.
		$known = [
			100, 101, 102, 200, 201, 202, 203, 204, 205, 206, 207, 208, 226,
			300, 301, 302, 303, 304, 305, 306, 307,
			400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 410, 411, 412,
			413, 414, 415, 416, 417, 418, 422, 423, 424, 426, 428, 429, 431,
			500, 501, 502, 503, 504, 505, 506, 507, 508, 509, 510, 511,
		];

		return in_array($status, $known, true) ? $status : $fallback;
	}

	private function readBinaryBool(mixed $value, bool $default): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (string)(int)$value === '1';
		}
		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, ['1', 'true'], true)) {
				return true;
			}
			if (in_array($normalized, ['0', 'false'], true)) {
				return false;
			}
		}

		return $default;
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
