<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Http\CookieHeaders;
use OCA\EtherpadNextcloud\Service\ApiErrorLog;
use OCA\EtherpadNextcloud\Service\LivePadHtml;
use OCA\EtherpadNextcloud\Service\PublicPadContext;
use OCA\EtherpadNextcloud\Service\PublicPadContextService;
use OCA\EtherpadNextcloud\Service\PublicShareResolver;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\PublicShareController;
use OCP\IRequest;
use OCP\ISession;
use OCP\Share\IShare;

/**
 * @psalm-api
 */
class PublicViewerController extends PublicShareController {
	private ?IShare $share = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private PublicShareResolver $shareResolver,
		private PublicPadContextService $padContextService,
		private PadResponseService $padResponses,
		private PublicViewerControllerErrorMapper $errors,
		ISession $session,
		private CookieHeaders $cookies,
	) {
		parent::__construct($appName, $request, $session);
	}

	public function isValidToken(): bool {
		try {
			$this->share = $this->shareResolver->resolveShare($this->getToken());
		} catch (InvalidShareTokenException) {
			return false;
		}

		return true;
	}

	protected function isPasswordProtected(): bool {
		return $this->share !== null && $this->share->getPassword() !== null;
	}

	protected function getPasswordHash(): ?string {
		return $this->share?->getPassword();
	}

	/**
	 * @see PadSessionController::contentById() for why this is its own endpoint.
	 *
	 * Throttled because each call makes this server fetch something on
	 * demand — the pad over the API, or a foreign export. 60 a minute is
	 * far more than reading and refreshing needs, and far less than a loop
	 * wants. Counted like the open: by address, or per user for one signed
	 * in.
	 */
	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	#[\OCP\AppFramework\Http\Attribute\AnonRateLimit(limit: 60, period: 60)]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 60, period: 60)]
	public function padContent(string $token, mixed $file = '', mixed $fileId = null): DataResponse {
		return $this->errors->runForData(
			fn(): LivePadHtml => $this->padContextService->resolveContent($token, $file, $this->share, $fileId),
			fn(LivePadHtml $content): DataResponse => $this->padResponses->padContentResponse($content),
			ApiErrorLog::fileNamedBy($this->request, byPath: false),
		);
	}

	/**
	 * Throttled because, for a writable link to a protected pad, each call
	 * starts an Etherpad session that lives for hours, and anyone holding
	 * the link could call it in a loop. Every visitor opens through here,
	 * and Nextcloud counts a visitor who is not signed in by address: a
	 * class behind one school's address opens a link all at once, so the
	 * limit is set well above that, and still cuts a loop to five calls a
	 * second. A signed-in visitor is counted on their own, not with the
	 * address they share; without a limit of their own, Nextcloud would
	 * count them by address too.
	 */
	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	#[\OCP\AppFramework\Http\Attribute\AnonRateLimit(limit: 300, period: 60)]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 300, period: 60)]
	public function openPadData(string $token, mixed $file = '', mixed $fileId = null): DataResponse {
		return $this->errors->runForData(
			fn(): PublicPadContext => $this->padContextService->resolve($token, $file, $this->share, $fileId),
			function (PublicPadContext $context): DataResponse {
				$response = new DataResponse([
					'title' => $context->title,
					'url' => $context->url,
					'is_external' => $context->isExternal,
					'is_readonly_view' => $context->isReadOnlyView,
					'original_pad_url' => $context->originalPadUrl,
					'content_url' => $context->contentUrl(),
				]);
				// Once the answer stands, and beside Nextcloud's own cookies,
				// not over them: the visitor's session cookie is one of them.
				$this->cookies->add($context->cookieHeader());
				return $response;
			},
			ApiErrorLog::fileNamedBy($this->request, byPath: false),
		);
	}

}
