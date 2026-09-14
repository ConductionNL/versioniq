<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Settings;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Settings\Admin;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\TestCase;

final class AdminTest extends TestCase {
	private function admin(bool $integriqEnabled = false, ?IInitialState $initialState = null): Admin {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $appId): bool => $appId === 'integriq' && $integriqEnabled,
		);

		return new Admin($initialState ?? $this->createMock(IInitialState::class), $appManager);
	}

	public function testGetSectionIsAppId(): void {
		self::assertSame('versioniq', $this->admin()->getSection());
	}

	public function testGetPriorityIsInt(): void {
		self::assertIsInt($this->admin()->getPriority());
	}

	public function testGetFormReturnsTemplateResponse(): void {
		$form = $this->admin()->getForm();
		self::assertInstanceOf(TemplateResponse::class, $form);
		// Embedded inside the settings page (empty renderAs).
		self::assertSame('', $form->getRenderAs());
		// The template name is the only binding to templates/index.php — assert it
		// so a typo there is caught here rather than as a runtime render error.
		self::assertSame('index', $form->getTemplateName());
		self::assertSame(Application::APP_ID, $form->getApp());
	}

	/**
	 * The Integrations tab renders only on this flag, so a page without
	 * integriq sends nothing to the integriq register (adopt-connection-registry).
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-003-an-admin-reads-the-connections-on-an-integrations-tab
	 */
	public function testTheFormTellsThePageWhetherIntegriqIsInstalled(): void {
		foreach ([true, false] as $enabled) {
			$initialState = $this->createMock(IInitialState::class);
			$initialState->expects($this->once())->method('provideInitialState')->with('integriq-installed', $enabled);

			$this->admin($enabled, $initialState)->getForm();
		}
	}
}
