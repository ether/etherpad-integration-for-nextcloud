<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCA\EtherpadNextcloud\Template\PadTemplateProvider;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Template\BeforeGetTemplatesEvent;
use OCP\IL10N;

/**
 * Puts the external tile's field back after other apps have had their say.
 *
 * Nextcloud dispatches this event before answering "what fields does this
 * template have?", and Collabora's listener replies for *every* template in
 * it, overwriting the fields of each with what its own extractor finds —
 * nothing, for our marker. The tile would then be created without ever asking
 * for the pad's address, so setting the fields in the provider alone is not
 * enough. Registered below the default priority, this runs after Collabora's
 * listener and after any other that kept the default.
 *
 * @template-implements IEventListener<Event>
 * @psalm-api
 */
class BeforeGetTemplatesListener implements IEventListener {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeGetTemplatesEvent) {
			return;
		}

		foreach ($event->getTemplates() as $template) {
			$serialized = $template->jsonSerialize();
			// The type as well as the id: Nextcloud keeps template ids per
			// provider, and `pad-external` is generic enough that another app
			// could hold the same one. Rewriting its fields would be our bug.
			if (($serialized['templateType'] ?? '') !== PadTemplateProvider::class) {
				continue;
			}
			if (($serialized['templateId'] ?? '') !== PadTemplateProvider::EXTERNAL_TEMPLATE_ID) {
				continue;
			}
			$template->setFields(PadTemplateProvider::padAddressFields($this->l10n));
		}
	}
}
