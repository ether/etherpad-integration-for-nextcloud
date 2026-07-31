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
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class LoadFilesScriptsListener implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private PadTypePolicy $padTypePolicy,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}

		// The "+ New" entries follow the admin's pad-type settings. This is
		// presentation only — creation itself is enforced in PadCreationService.
		$this->initialState->provideInitialState('pad_types', [
			'protected' => $this->padTypePolicy->isEnabled(BindingService::ACCESS_PROTECTED),
			'public' => $this->padTypePolicy->isEnabled(BindingService::ACCESS_PUBLIC),
		]);

		Util::addStyle(Application::APP_ID, 'files-main');
		Util::addScript(Application::APP_ID, 'etherpad_nextcloud-files-main', 'files');
		Util::addScript(Application::APP_ID, 'etherpad_nextcloud-viewer-main', 'files');
	}
}
