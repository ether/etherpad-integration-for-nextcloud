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
use OCA\EtherpadNextcloud\Exception\EtherpadRefusedException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\NoShareFileSelectedException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\ShareFileNotInShareException;
use OCA\EtherpadNextcloud\Exception\ShareItemUnavailableException;
use OCA\EtherpadNextcloud\Exception\ShareReadForbiddenException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Service\ApiErrorLog;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\Lock\LockedException;

/**
 * Maps what a public share's endpoints throw to what an anonymous visitor
 * reads: one status and one translated sentence for each kind of trouble,
 * in answerFor(); an exception's message is for the log. The `code` comes
 * from ApiErrorCode, less the codes whose action needs a signed-in user.
 *
 * Each is reported once through ApiErrorLog, which picks the level; a 500
 * is unforeseen. No file in the context: the request names it by a share
 * token, which stays out of the log.
 */
class PublicViewerControllerErrorMapper {
	public function __construct(
		private PublicShareUrlBuilder $shareUrlBuilder,
		private PadResponseService $padResponses,
		private IL10N $l10n,
		private ApiErrorLog $errorLog,
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
			[$status, $message] = $this->answerFor($e);
			$this->report($e, $status);
			// Same shape as the signed-in endpoint: a client that wants to
			// treat "too large" differently from any other 400 has to be
			// able to see it, and a message is not something to branch on.
			return new DataResponse(ApiErrorCode::addTo(['message' => $message], $e, onAPublicShare: true), $status);
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
			[$status, $message] = $this->answerFor($e);
			$this->report($e, $status);
			$response = new TemplateResponse(Application::APP_ID, 'noviewer', [
				'error' => $message,
				'back_url' => $this->shareUrlBuilder->buildShareBaseUrl($token),
				'back_label' => $this->l10n->t('Back to shared files'),
			], TemplateResponse::RENDER_AS_BLANK);
			$response->setStatus($status);
			return $response;
		}
	}

	/**
	 * The status and the sentence. DataResponse takes the set of valid HTTP
	 * codes, not any int, so a wider type would not pass.
	 *
	 * @return array{0: Http::STATUS_*, 1: string}
	 */
	private function answerFor(\Throwable $e): array {
		return match (true) {
			$e instanceof InvalidShareTokenException => [Http::STATUS_NOT_FOUND, $this->l10n->t('This share link is invalid or has expired.')],
			$e instanceof ShareItemUnavailableException => [Http::STATUS_NOT_FOUND, $this->l10n->t('This shared item is no longer available.')],
			$e instanceof ShareFileNotInShareException => [Http::STATUS_NOT_FOUND, $this->l10n->t('The selected file is not part of this share.')],
			// Gone after the share found it: deleted in between, say.
			$e instanceof NotFoundException => [Http::STATUS_NOT_FOUND, $this->l10n->t('This shared item is no longer available.')],
			$e instanceof ShareReadForbiddenException => [Http::STATUS_FORBIDDEN, $this->l10n->t('This share link does not allow reading files.')],
			// By id, by path, or the two naming different files.
			$e instanceof InvalidShareFilePathException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('This link does not point to a valid file.')],
			$e instanceof NoShareFileSelectedException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('No .pad file selected. Open a .pad file from this shared folder.')],
			// A folder too.
			$e instanceof NotAPadFileException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('The selected item is not a .pad document.')],
			$e instanceof MissingFrontmatterException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('The selected .pad file is missing required metadata.')],
			$e instanceof PadFileFormatException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('The selected .pad file has an invalid format.')],
			// A copy, or an original whose pad the sweep let go: either way
			// its owner, opening it, is offered the pad back.
			$e instanceof MissingBindingException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('This .pad file has no pad in this Nextcloud. Its owner can open it to restore the pad.')],
			// The signed-in sentence, translated like it.
			$e instanceof WaitingBindingException => [Http::STATUS_CONFLICT, $this->padResponses->bindingErrorMessage($e)],
			$e instanceof BindingException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('Pad binding is inconsistent. Please contact the share owner.')],
			// Passes by itself, as on the signed-in side.
			$e instanceof LockedException => [Http::STATUS_SERVICE_UNAVAILABLE, $this->l10n->t('Pad file is temporarily locked. Please retry.')],
			// Not an outage: the pad answered, and this preview will not
			// read that much of it.
			$e instanceof EtherpadTooLargeException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('This pad is too large to show here. Open it in Etherpad instead.')],
			$e instanceof EtherpadRefusedException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('Etherpad refused to open this shared pad. Please contact the share owner.')],
			$e instanceof ExternalPadException => [Http::STATUS_BAD_REQUEST, $this->l10n->t('The pad this file links to on another server could not be read.')],
			// Not reachable: worth trying again, as a locked file is.
			$e instanceof EtherpadClientException => [Http::STATUS_SERVICE_UNAVAILABLE, $this->l10n->t('Etherpad is currently unavailable for this shared pad.')],
			default => [Http::STATUS_INTERNAL_SERVER_ERROR, $this->l10n->t('Could not open pad')],
		};
	}

	private function report(\Throwable $e, int $status): void {
		$this->errorLog->report($e, [], $status === Http::STATUS_INTERNAL_SERVER_ERROR ? 'Unhandled public viewer error' : null);
	}
}
