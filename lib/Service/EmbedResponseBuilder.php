<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Service;

use OC\Security\CSRF\CsrfTokenManager;
use OCA\EtherpadNextcloud\AppInfo\Application;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;

/**
 * Builds embed-template responses for the EmbedController.
 *
 * Encapsulates the embed-specific plumbing: per-request caching of the embed
 * base data (request token + trusted embed origins), template assembly via the
 * blank renderer, and frame-ancestor CSP application.
 */
class EmbedResponseBuilder {
	/** @var array{requesttoken:string,trusted_embed_origins:list<string>}|null */
	private ?array $embedBaseData = null;

	public function __construct(
		// Intentional use of the internal manager:
		// blank embed templates do not get the normal Nextcloud layout bootstrap,
		// so OC.requestToken is not auto-injected there. In this NC version there
		// is no public OCP CSRF-token service for this use-case, so the encrypted
		// token has to be passed manually into the blank template.
		private CsrfTokenManager $csrfTokenManager,
		private AppConfigService $appConfigService,
	) {
	}

	/** @param array<string,mixed> $data */
	public function build(string $template, array $data): TemplateResponse {
		$response = new TemplateResponse(
			Application::APP_ID,
			$template,
			array_merge($this->getBaseData(), $data),
			'blank'
		);

		return $this->applyEmbedPolicy($response);
	}

	/** @return array{requesttoken:string,trusted_embed_origins:list<string>} */
	private function getBaseData(): array {
		if ($this->embedBaseData !== null) {
			return $this->embedBaseData;
		}

		$this->embedBaseData = [
			'requesttoken' => $this->csrfTokenManager->getToken()->getEncryptedValue(),
			'trusted_embed_origins' => $this->appConfigService->getTrustedEmbedOrigins(),
		];

		return $this->embedBaseData;
	}

	private function applyEmbedPolicy(TemplateResponse $response): TemplateResponse {
		$policy = new ContentSecurityPolicy();
		foreach ($this->getBaseData()['trusted_embed_origins'] as $origin) {
			$policy->addAllowedFrameAncestorDomain($origin);
		}
		$response->setContentSecurityPolicy($policy);

		return $response;
	}
}
