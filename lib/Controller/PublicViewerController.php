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
use OCA\EtherpadNextcloud\Service\LivePadHtml;
use OCA\EtherpadNextcloud\Service\PublicPadContext;
use OCA\EtherpadNextcloud\Service\PublicPadContextService;
use OCA\EtherpadNextcloud\Service\PublicShareResolver;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\PublicShareController;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
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
		private PublicShareUrlBuilder $shareUrlBuilder,
		private PadResponseService $padResponses,
		private PublicViewerControllerErrorMapper $errors,
		ISession $session,
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

	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function showPad(string $token): RedirectResponse|TemplateResponse {
		return $this->errors->runForTemplate(
			fn(): string => $this->shareUrlBuilder->buildShareRedirectUrl($token, $this->request->getParam('file', '')),
			static fn(string $target): RedirectResponse => new RedirectResponse($target),
			$token,
		);
	}

	/** @see PadSessionController::contentById() for why this is its own endpoint. */
	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function padContent(string $token, mixed $file = ''): DataResponse {
		return $this->errors->runForData(
			fn(): LivePadHtml => $this->padContextService->resolveContent($token, $file, $this->share),
			fn(LivePadHtml $content): DataResponse => $this->padResponses->padContentResponse($content),
		);
	}

	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function openPadData(string $token, mixed $file = ''): DataResponse {
		return $this->errors->runForData(
			fn(): PublicPadContext => $this->padContextService->resolve($token, $file, $this->share),
			function (PublicPadContext $context): DataResponse {
				$response = new DataResponse([
					'title' => $context->title,
					'url' => $context->url,
					'is_external' => $context->isExternal,
					'is_readonly_view' => $context->isReadOnlyView,
					'original_pad_url' => $context->originalPadUrl,
					'content_url' => $context->contentUrl,
				]);
				if ($context->cookieHeader !== '') {
					$response->addHeader('Set-Cookie', $context->cookieHeader);
				}
				return $response;
			},
		);
	}

}
