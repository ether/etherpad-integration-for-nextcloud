<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Files\Template\TemplateFileCreator;
use OCP\IL10N;

/**
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class RegisterTemplateCreatorListener implements IEventListener {
	public function __construct(
		private IL10N $l10n,
		private PadTypePolicy $padTypePolicy,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof RegisterTemplateCreatorEvent)) {
			return;
		}

		// Nextcloud's own "+ New" entry always produces a protected pad — the
		// blank file it creates is bootstrapped as one. Hide the entry when
		// that type is switched off; public pads have their own menu entries.
		if (!$this->padTypePolicy->isEnabled(BindingService::ACCESS_PROTECTED)) {
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
}
