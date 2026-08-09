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
		// Nextcloud dispatches this twice: once to list the templates, once to
		// answer what fields one has. Only the second dispatch matters — the
		// picker asks for fields through its own endpoint after a tile is
		// chosen, on 31, 32 and 33 alike — so what the listing carries is never
		// read. Ignoring it saves walking every template on every picker open.
		//
		// method_exists because the flag is documented as Nextcloud 32 even
		// though 31 ships it; the guard costs nothing and outlives the tag.
		if (method_exists($event, 'shouldGetFields') && !$event->shouldGetFields()) {
			return;
		}

		foreach ($event->getTemplates() as $template) {
			$serialized = $template->jsonSerialize();
			// The type as well as the id: Nextcloud keeps all providers'
			// templates in one map keyed by the id, so an id says less about
			// ownership than it looks. Rewriting another app's fields would be
			// our bug.
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
