<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\ReleaseAlert;

use OCA\Versioniq\AppInfo\Application;
use OCP\IURLGenerator;
use XMLWriter;

/**
 * The release log as an RSS 2.0 document (releases-new-release-alerts, design D4).
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-new-releases-are-available-as-an-rss-feed-for-admins
 *
 * @psalm-import-type ReleaseEvent from ReleaseEventDetector
 */
class ReleaseFeed {
	public function __construct(
		private ReleaseEventLog $log,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * One item per logged release, newest first.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-new-releases-are-available-as-an-rss-feed-for-admins
	 */
	public function render(): string {
		$link = $this->adminPage();

		$xml = new XMLWriter();
		$xml->openMemory();
		$xml->startDocument('1.0', 'UTF-8');
		$xml->startElement('rss');
		$xml->writeAttribute('version', '2.0');
		$xml->startElement('channel');
		$xml->writeElement('title', 'Versioniq: new releases');
		$xml->writeElement('link', $link);
		$xml->writeElement('description', 'New versions of the apps this Nextcloud runs or follows.');
		foreach ($this->log->all() as $event) {
			$this->writeItem($xml, $event);
		}
		$xml->endElement();
		$xml->endElement();
		$xml->endDocument();

		return $xml->outputMemory();
	}

	/**
	 * @param ReleaseEvent $event
	 */
	private function writeItem(XMLWriter $xml, array $event): void {
		$xml->startElement('item');
		$xml->writeElement('title', $event['appId'] . ' ' . $event['version']);
		$xml->writeElement('link', $this->adminPage());
		$xml->writeElement('guid', $event['appId'] . ':' . $event['version']);
		$xml->writeElement('category', (string)($event['sourceId'] ?? ''));
		$xml->writeElement('pubDate', gmdate(DATE_RSS, self::publishedAt($event)));
		$xml->endElement();
	}

	/**
	 * The Versioniq admin page every item links to.
	 */
	private function adminPage(): string {
		return $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]);
	}

	/**
	 * The release date when known, else the time it was detected.
	 *
	 * @param ReleaseEvent $event
	 */
	private static function publishedAt(array $event): int {
		$releasedAt = $event['releasedAt'] ?? null;

		return is_int($releasedAt) ? $releasedAt : (int)$event['detectedAt'];
	}
}
