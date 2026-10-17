<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Service\AppAccess;
use OCA\EtherpadNextcloud\Service\PadTypePolicy;
use OCA\EtherpadNextcloud\Util\PadFileType;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Files\Template\TemplateFileCreator;
use OCP\IConfig;
use OCP\IL10N;

/**
 * @template-implements IEventListener<Event>
 */
class RegisterTemplateCreatorListener implements IEventListener {
	public function __construct(
		private IL10N $l10n,
		private PadTypePolicy $padTypePolicy,
		private IConfig $config,
		private AppAccess $appAccess,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof RegisterTemplateCreatorEvent)) {
			return;
		}

		// The entry is also the way to the template picker, so it stays while
		// anything can be created: external pads too, which follow their own
		// setting rather than a pad type.
		if (!$this->anythingCanBeCreated() || !$this->appAccess->isEnabledForCurrentUser()) {
			return;
		}

		$event->getTemplateManager()->registerTemplateFileCreator(function (): TemplateFileCreator {
			$creator = new TemplateFileCreator(Application::APP_ID, $this->l10n->t('New pad'), PadFileType::SUFFIX);
			$creator->addMimetype(PadFileType::MIME);
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
