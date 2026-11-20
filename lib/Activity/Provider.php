<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Activity;

use OCA\Versioniq\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Renders Versioniq's version changes in the activity stream
 * (audit-attribution-and-forwarding D4): installs, rollbacks, pins, unpins and
 * policy changes, each naming the admin who made it.
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-audit-rows-reach-the-platforms-audit-log-and-activity-stream
 */
class Provider implements IProvider {
	public const TYPE = 'versioniq_version_change';

	public const SUBJECT_INSTALL = 'install';
	public const SUBJECT_ROLLBACK = 'rollback';
	public const SUBJECT_PIN = 'pin';
	public const SUBJECT_UNPIN = 'unpin';
	public const SUBJECT_POLICY_SET = 'policy_set';
	public const SUBJECT_POLICY_CLEAR = 'policy_clear';

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @throws UnknownActivityException For an event that is not a Versioniq version change.
	 */
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID || $event->getType() !== self::TYPE) {
			throw new UnknownActivityException();
		}

		$template = $this->template(l: $this->l10nFactory->get(Application::APP_ID, $language), subject: $event->getSubject());
		$parameters = self::richParameters(params: $event->getSubjectParameters());
		$event->setRichSubject($template, $parameters);
		$event->setParsedSubject(self::plain(template: $template, parameters: $parameters));
		$event->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));

		return $event;
	}

	/**
	 * The subject's sentence, with {actor}, {app}, {from}, {to} and {level} placeholders.
	 *
	 * @throws UnknownActivityException For a subject this provider does not render.
	 */
	private function template(IL10N $l, string $subject): string {
		return match ($subject) {
			self::SUBJECT_INSTALL => $l->t('{actor} updated {app} from {from} to {to}'),
			self::SUBJECT_ROLLBACK => $l->t('{actor} rolled back {app} from {from} to {to}'),
			self::SUBJECT_PIN => $l->t('{actor} pinned {app} to {to}'),
			self::SUBJECT_UNPIN => $l->t('{actor} unpinned {app}'),
			self::SUBJECT_POLICY_SET => $l->t('{actor} set the automatic update policy of {app} to {level}'),
			self::SUBJECT_POLICY_CLEAR => $l->t('{actor} cleared the automatic update policy of {app}'),
			default => throw new UnknownActivityException(),
		};
	}

	/**
	 * The rich-object parameters: the actor as a user, the rest as highlighted text.
	 *
	 * @param array<array-key, mixed> $params The stored subject parameters.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function richParameters(array $params): array {
		$actor = (string)($params['actor'] ?? '');
		$rich = [
			'actor' => $actor === 'system'
				? ['type' => 'highlight', 'id' => 'system', 'name' => 'system']
				: ['type' => 'user', 'id' => $actor, 'name' => $actor],
		];
		foreach (['app', 'from', 'to', 'level'] as $key) {
			$value = (string)($params[$key] ?? '');
			$rich[$key] = ['type' => 'highlight', 'id' => $value, 'name' => $value === '' ? '-' : $value];
		}

		return $rich;
	}

	/**
	 * The sentence with each placeholder replaced by its name.
	 *
	 * @param array<string, array<string, string>> $parameters The rich parameters.
	 */
	private static function plain(string $template, array $parameters): string {
		$replace = [];
		foreach ($parameters as $key => $parameter) {
			$replace['{' . $key . '}'] = $parameter['name'];
		}

		return strtr($template, $replace);
	}
}
