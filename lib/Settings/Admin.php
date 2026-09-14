<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Settings;

use OCA\Versioniq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

/**
 * Renders the Versioniq Vue SPA as the body of its admin settings section.
 * The empty `renderAs` argument embeds the template inside the settings page
 * rather than as a standalone full-page app.
 *
 * @spec openspec/specs/version-management/spec.md
 * @psalm-api
 */
class Admin implements ISettings {
	/**
	 * The initial-state key that tells the page whether integriq is installed.
	 * The Integrations tab renders only when it is true, so without integriq
	 * nothing asks the `integriq` register (adopt-connection-registry).
	 */
	public const STATE_INTEGRIQ_INSTALLED = 'integriq-installed';

	/**
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
	 */
	public function __construct(
		private IInitialState $initialState,
		private IAppManager $appManager,
	) {
	}

	/**
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
	 */
	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState(
			self::STATE_INTEGRIQ_INSTALLED,
			$this->appManager->isEnabledForUser('integriq'),
		);

		return new TemplateResponse(Application::APP_ID, 'index', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
