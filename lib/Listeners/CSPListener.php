<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Listeners;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * @template-implements IEventListener<Event>
 */
class CSPListener implements IEventListener {
	public function __construct(
		private IConfig $config,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}

		$etherpadHost = rtrim((string)$this->config->getAppValue('etherpad_nextcloud', 'etherpad_host', ''), '/');
		if ($etherpadHost === '') {
			return;
		}

		// `frame-src` only, never `child-src` as well. Pages that set no
		// `worker-src` of their own — a public share does not — fall back to
		// `child-src`, so a host listed there is the only worker source left
		// and Nextcloud's own workers stop loading. Nextcloud 34 has no
		// addAllowedChildSrcDomain() at all.
		//
		// This answers every page of the server, so only the Etherpad that
		// is framed: a pad on another server is shown in the app's own view,
		// and the HTML its server sends there has no frame to load.
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain($etherpadHost);
		$event->addPolicy($policy);
	}
}
