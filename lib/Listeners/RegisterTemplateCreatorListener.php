<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Files\Template\TemplateFileCreator;
use OCP\IConfig;
use OCP\IL10N;

/**
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class RegisterTemplateCreatorListener implements IEventListener {
	public function __construct(
		private IL10N $l10n,
		private PadTypePolicy $padTypePolicy,
		private IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof RegisterTemplateCreatorEvent)) {
			return;
		}

		// This entry is the only way to create a pad from the Files app – and
		// the only way to reach the shared templates and the tiles – so it
		// stays as long as anything at all can be created. External pads count:
		// they are not a pad type and follow their own setting, so an instance
		// offering only those would otherwise have a tile nobody can open.
		if (!$this->anythingCanBeCreated()) {
			return;
		}

		$event->getTemplateManager()->registerTemplateFileCreator(function (): TemplateFileCreator {
			$creator = new TemplateFileCreator(Application::APP_ID, $this->l10n->t('New pad'), '.pad');
			$creator->addMimetype('application/x-etherpad-nextcloud');
			$creator->setActionLabel($this->l10n->t('New pad'));
			$creator->setOrder(98);
			$iconPath = __DIR__ . '/../../img/etherpad-icon-color.svg';
			if (is_file($iconPath)) {
				$icon = file_get_contents($iconPath);
				if (is_string($icon) && $icon !== '') {
					$creator->setIconSvgInline($icon);
				}
			}
			return $creator;
		});
	}

	private function anythingCanBeCreated(): bool {
		return $this->padTypePolicy->hasAnyEnabledType()
			|| (string)$this->config->getAppValue(Application::APP_ID, 'allow_external_pads', 'no') === 'yes';
	}
}
