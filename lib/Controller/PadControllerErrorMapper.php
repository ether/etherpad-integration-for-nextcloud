<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\LegacyProtectedImportDisabledException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Exception\InvalidPadNameException;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Centralizes mapping domain and framework exceptions to HTTP DataResponses.
 * RuntimeException messages are intentionally not exposed to clients to
 * avoid leaking internal details.
 *
 * The sentences for the exceptions it knows are its own, translated; an
 * endpoint passes wording only where it means something else by the same
 * exception, and the `code` comes from ApiErrorCode. What a client gets for
 * Etherpad failing, and for anything unforeseen, is reported once: through
 * the endpoint's `on_throwable`, or a line of its own.
 *
 * The wording an endpoint may override is named once, here, and imported by
 * everything that passes it along: a caller typing it as a plain array hands
 * DataResponse an int where it takes the set of valid HTTP codes.
 *
 * @psalm-type ErrorWording = array{
 *   invalid_argument?: string,
 *   not_found?: string,
 *   binding_message?: string,
 *   binding_status?: 400|403|404|409|500,
 *   generic?: string,
 *   map_throwable?: callable(\Throwable): ?DataResponse,
 *   on_throwable?: callable(\Throwable): void
 * }
 */
class PadControllerErrorMapper {
	public function __construct(
		private PadResponseService $padResponses,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): DataResponse $success
	 * @param ErrorWording $options
	 */
	public function run(callable $action, callable $success, array $options = []): DataResponse {
		try {
			return $success($action());
		} catch (UnauthorizedRequestException $e) {
			return $this->answer($e, [
				'message' => $e->getMessage() !== '' ? $e->getMessage() : $this->l10n->t('Authentication required.'),
			], Http::STATUS_UNAUTHORIZED);
		} catch (ControllerBadRequestException $e) {
			return $this->answer($e, [
				'message' => $e->getMessage() !== '' ? $e->getMessage() : $this->l10n->t('Invalid input.'),
			], Http::STATUS_BAD_REQUEST);
		} catch (InvalidPadNameException $e) {
			// Nextcloud said which rule the name broke, and that sentence is
			// worth more to the user than "Invalid pad name".
			return $this->answer($e, [
				'message' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			$configuredMessage = $options['invalid_argument'] ?? '';
			$exceptionMessage = trim($e->getMessage());
			$message = $configuredMessage !== ''
				? $configuredMessage
				: ($exceptionMessage !== '' ? $exceptionMessage : $this->l10n->t('Invalid input.'));

			return $this->answer($e, [
				'message' => $message,
			], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return $this->answer($e, [
				'message' => $options['not_found'] ?? $this->l10n->t('Resource not found.'),
			], Http::STATUS_NOT_FOUND);
		} catch (LockedException $e) {
			return $this->answer($e, [
				'message' => $this->l10n->t('Pad file is temporarily locked. Please retry.'),
				'retryable' => true,
			], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (PadFileChangedException $e) {
			return $this->answer($e, [
				'message' => $this->l10n->t('The target file changed while the pad was being created. Try again with a new name.'),
			], Http::STATUS_CONFLICT);
		} catch (PadFileAlreadyExistsException $e) {
			return $this->answer($e, [
				'message' => $this->l10n->t('A file with this name already exists.'),
			], Http::STATUS_CONFLICT);
		} catch (PadAlreadyHasBindingException $e) {
			return $this->answer($e, [
				'message' => $this->l10n->t('This .pad file is already linked to a pad.'),
			], Http::STATUS_CONFLICT);
		} catch (PadParentFolderNotWritableException $e) {
			return $this->answer($e, [
				'message' => $this->l10n->t('Selected parent folder is not writable.'),
			], Http::STATUS_FORBIDDEN);
		} catch (PadTypeDisabledException $e) {
			// Fixed wording plus a stable code, like the other structured
			// errors here — the exception's own message stays internal.
			$payload = [
				'message' => $this->l10n->t('This pad type is disabled on this instance.'),
			];
			if ($e->getAccessMode() !== '') {
				$payload['access_mode'] = $e->getAccessMode();
			}
			return $this->answer($e, $payload, Http::STATUS_FORBIDDEN);
		} catch (BindingException $e) {
			$status = $options['binding_status'] ?? Http::STATUS_BAD_REQUEST;
			if (isset($options['binding_message'])) {
				// The caller's wording for its own conflict. No code of ours
				// goes with a message that is not ours.
				return new DataResponse(['message' => $options['binding_message']], $status);
			}
			if ($e instanceof WaitingBindingException) {
				// Not a dead end: once the sweep has settled the row, the same
				// request opens the pad - or answers missing_binding, when the
				// pad had to be let go.
				$status = Http::STATUS_CONFLICT;
			}
			// missing_binding lets the UI offer a recovery action instead of
			// showing a dead-end error.
			return $this->answer($e, ['message' => $this->padResponses->bindingErrorMessage($e)], $status);
		} catch (LegacyProtectedImportDisabledException $e) {
			// 403, not 409: nothing conflicts, the instance does not offer
			// this import at all.
			return $this->answer($e, [
				'message' => $this->l10n->t('This file is a legacy Ownpad link to a protected pad, and importing those is disabled on this server. Please contact your administrator.'),
			], Http::STATUS_FORBIDDEN);
		} catch (LegacyPadCollisionException $e) {
			// The legacy Ownpad shortcut points at a pad-id that is already
			// bound to another file the requesting user has no access to.
			// 409 (not 403): the conflict is with the *other* file's
			// binding, not access-denied to the file we're opening.
			return $this->answer($e, [
				'message' => $e->getMessage(),
			], Http::STATUS_CONFLICT);
		} catch (EtherpadTooLargeException $e) {
			// The pad is fine and still editable; only this preview refuses
			// to read that much. Its own wording, because the byte count in
			// the exception explains nothing to a reader.
			return $this->answer($e, [
				'message' => $this->l10n->t('This pad is too large to show here. Open it in Etherpad instead.'),
			], Http::STATUS_BAD_REQUEST);
		} catch (MissingFrontmatterException $e) {
			// The one .pad problem the clients act on rather than report:
			// they initialise the file and retry, by the code - they used to
			// search the English message for a phrase, which a translation or
			// a reworded throw would have broken silently.
			return $this->answer($e, [
				'message' => $this->l10n->t('This .pad file has no pad metadata yet.'),
			], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException $e) {
			return $this->answer($e, ['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (EtherpadClientException $e) {
			// Etherpad's own sentence still goes to the client - for a pad on
			// another server it says what was wrong with the link - but the
			// failure is the admin's to see as well.
			$this->report($e, $options);
			return $this->answer($e, ['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$mapped = $this->mapThrowable($e, $options);
			if ($mapped instanceof DataResponse) {
				return $mapped;
			}
			$this->report($e, $options);
			return new DataResponse([
				'message' => $options['generic'] ?? $this->l10n->t('Request failed.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param Http::STATUS_* $status
	 */
	private function answer(\Throwable $e, array $payload, int $status): DataResponse {
		return new DataResponse(ApiErrorCode::addTo($payload, $e), $status);
	}

	/** @param array<string,mixed> $options */
	private function mapThrowable(\Throwable $e, array $options): ?DataResponse {
		$mapper = $options['map_throwable'] ?? null;
		if (is_callable($mapper)) {
			$response = $mapper($e);
			if ($response instanceof DataResponse) {
				return $response;
			}
		}
		return null;
	}

	/**
	 * One line for a failure the client is answered for: the endpoint's,
	 * which names its file, or this mapper's.
	 *
	 * @param array<string,mixed> $options
	 */
	private function report(\Throwable $e, array $options): void {
		$logger = $options['on_throwable'] ?? null;
		if (is_callable($logger)) {
			$logger($e);
			return;
		}
		$context = ['app' => 'etherpad_nextcloud', ...SafeError::context($e)];
		if ($e instanceof EtherpadClientException) {
			$this->logger->warning('Etherpad failed while answering a pad request.', $context);
		} else {
			$this->logger->error('Unhandled pad controller error', $context);
		}
	}
}
