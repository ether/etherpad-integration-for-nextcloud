<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\AppInfo\Application;
use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\NoShareFileSelectedException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\ShareFileNotInShareException;
use OCA\EtherpadNextcloud\Exception\ShareItemUnavailableException;
use OCA\EtherpadNextcloud\Exception\ShareReadForbiddenException;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Maps what a public share's endpoints throw to what an anonymous visitor
 * reads. The sentences are this mapper's own, translated, one for each
 * kind of trouble: an exception's message is for the log. The `code` comes
 * from ApiErrorCode, less the codes whose action needs a signed-in user.
 */
class PublicViewerControllerErrorMapper {
	public function __construct(
		private PublicShareUrlBuilder $shareUrlBuilder,
		private PadResponseService $padResponses,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): DataResponse $success
	 */
	public function runForData(callable $action, callable $success): DataResponse {
		try {
			return $success($action());
		} catch (\Throwable $e) {
			$this->report($e);
			// Same shape as the signed-in endpoint: a client that wants to
			// treat "too large" differently from any other 400 has to be
			// able to see it, and a message is not something to branch on.
			return new DataResponse(
				ApiErrorCode::addTo(['message' => $this->messageFor($e)], $e, onAPublicShare: true),
				$this->statusFor($e),
			);
		}
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): (RedirectResponse|TemplateResponse) $success
	 */
	public function runForTemplate(callable $action, callable $success, string $token): RedirectResponse|TemplateResponse {
		try {
			return $success($action());
		} catch (\Throwable $e) {
			$this->report($e);
			$response = new TemplateResponse(Application::APP_ID, 'noviewer', [
				'error' => $this->messageFor($e),
				'back_url' => $this->shareUrlBuilder->buildShareBaseUrl($token),
				'back_label' => $this->l10n->t('Back to shared files'),
			], TemplateResponse::RENDER_AS_BLANK);
			$response->setStatus($this->statusFor($e));
			return $response;
		}
	}

	/**
	 * The five the match below can reach. DataResponse takes the set of
	 * valid HTTP codes, not any int, so a wider type would not pass.
	 *
	 * @return 400|403|404|409|500
	 */
	private function statusFor(\Throwable $e): int {
		return match (true) {
			$e instanceof InvalidShareTokenException,
			$e instanceof ShareItemUnavailableException,
			$e instanceof ShareFileNotInShareException => Http::STATUS_NOT_FOUND,
			$e instanceof ShareReadForbiddenException => Http::STATUS_FORBIDDEN,
			$e instanceof WaitingBindingException => Http::STATUS_CONFLICT,
			$e instanceof InvalidShareFilePathException,
			$e instanceof NoShareFileSelectedException,
			$e instanceof NotAPadFileException,
			$e instanceof PadFileFormatException,
			$e instanceof BindingException,
			$e instanceof EtherpadClientException => Http::STATUS_BAD_REQUEST,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};
	}

	private function messageFor(\Throwable $e): string {
		return match (true) {
			$e instanceof InvalidShareTokenException => $this->l10n->t('This share link is invalid or has expired.'),
			$e instanceof ShareItemUnavailableException => $this->l10n->t('This shared item is no longer available.'),
			$e instanceof ShareFileNotInShareException => $this->l10n->t('The selected file is not part of this share.'),
			$e instanceof ShareReadForbiddenException => $this->l10n->t('This share link does not allow reading files.'),
			$e instanceof InvalidShareFilePathException => $this->l10n->t('Invalid file path.'),
			$e instanceof NoShareFileSelectedException => $this->l10n->t('No .pad file selected. Open a .pad file from this shared folder.'),
			$e instanceof NotAPadFileException => $this->l10n->t('The selected file is not a .pad document.'),
			$e instanceof MissingFrontmatterException => $this->l10n->t('The selected .pad file is missing required metadata.'),
			$e instanceof PadFileFormatException => $this->l10n->t('The selected .pad file has an invalid format.'),
			// A copy, or an original whose pad the sweep let go: either way
			// its owner, opening it, is offered the pad back.
			$e instanceof MissingBindingException => $this->l10n->t('This .pad file has no pad in this Nextcloud. Its owner can open it to restore the pad.'),
			// The signed-in sentence, translated like it.
			$e instanceof WaitingBindingException => $this->padResponses->bindingErrorMessage($e),
			$e instanceof BindingException => $this->l10n->t('Pad binding is inconsistent. Please contact the share owner.'),
			// Not an outage: the pad answered, and this preview will not
			// read that much of it.
			$e instanceof EtherpadTooLargeException => $this->l10n->t('This pad is too large to show here. Open it in Etherpad instead.'),
			$e instanceof EtherpadClientException => $this->l10n->t('Etherpad is currently unavailable for this shared pad.'),
			default => $this->l10n->t('Could not open pad'),
		};
	}

	/**
	 * Etherpad failing is the admin's to see, as on the signed-in side;
	 * what a visitor's link or file got wrong is not, and anything else is
	 * unforeseen.
	 */
	private function report(\Throwable $e): void {
		$context = ['app' => Application::APP_ID, ...SafeError::context($e)];
		if ($e instanceof EtherpadClientException && !$e instanceof EtherpadTooLargeException) {
			$this->logger->warning('Etherpad failed while answering a public share request.', $context);
		} elseif (!$this->isExpectedPublicError($e)) {
			$this->logger->error('Unhandled public viewer error', $context);
		}
	}

	private function isExpectedPublicError(\Throwable $e): bool {
		return $e instanceof InvalidShareTokenException
			|| $e instanceof ShareItemUnavailableException
			|| $e instanceof ShareFileNotInShareException
			|| $e instanceof ShareReadForbiddenException
			|| $e instanceof InvalidShareFilePathException
			|| $e instanceof NoShareFileSelectedException
			|| $e instanceof NotAPadFileException
			|| $e instanceof PadFileFormatException
			|| $e instanceof BindingException
			|| $e instanceof EtherpadTooLargeException;
	}
}
