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
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\InvalidPadNameException;
use OCA\EtherpadNextcloud\Exception\LegacyPadCollisionException;
use OCA\EtherpadNextcloud\Exception\LegacyProtectedImportDisabledException;
use OCA\EtherpadNextcloud\Exception\MissingFrontmatterException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadAlreadyHasBindingException;
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadFileChangedException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\PadTypeDisabledException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Exception\WaitingBindingException;
use OCA\EtherpadNextcloud\Service\EtherpadFailureLog;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Util\SafeError;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Maps what the signed-in pad API throws to the response a client gets.
 *
 * The sentence is this mapper's own, translated; an exception's message is
 * for the log. Three pass their message on: a controller's own refusal of
 * a parameter and a refused name, both translated where they are thrown,
 * and a pad on another server that cannot be linked or read, whose reason
 * is the user's only hint (in English). The `code` comes from ApiErrorCode.
 *
 * An endpoint passes wording only where it means something else by the
 * same exception. This instance's Etherpad failing is logged through
 * EtherpadFailureLog; anything unforeseen as an error, under the endpoint's
 * `failure` line where it has one. Both name the request's file
 * (`context`, which runForUser() fills in).
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
 *   failure?: string,
 *   context?: array<string, int|string>
 * }
 */
class PadControllerErrorMapper {
	public function __construct(
		private PadResponseService $padResponses,
		private IL10N $l10n,
		private EtherpadFailureLog $etherpadFailures,
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
			return $this->answer($e, ['message' => $this->l10n->t('Authentication required.')], Http::STATUS_UNAUTHORIZED);
		} catch (ControllerBadRequestException $e) {
			// Which parameter, translated where it was refused.
			return $this->answer($e, [
				'message' => $e->getMessage() !== '' ? $e->getMessage() : $this->l10n->t('Invalid input.'),
			], Http::STATUS_BAD_REQUEST);
		} catch (InvalidPadNameException $e) {
			// Nextcloud said which rule the name broke, and that sentence is
			// worth more to the user than "Invalid pad name".
			return $this->answer($e, ['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NotAPadFileException $e) {
			return $this->answer($e, ['message' => $this->l10n->t('Selected file is not a .pad file.')], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return $this->answer($e, [
				'message' => $options['invalid_argument'] ?? $this->l10n->t('Invalid input.'),
			], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return $this->answer($e, [
				'message' => $options['not_found'] ?? $this->l10n->t('.pad file not found.'),
			], Http::STATUS_NOT_FOUND);
		} catch (LockedException $e) {
			return $this->answer($e, [
				'message' => $this->l10n->t('Pad file is temporarily locked. Please retry.'),
				'retryable' => true,
			], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (PadFileChangedException $e) {
			// Creating and initialising a file both throw it; trying again
			// is right for both.
			return $this->answer($e, [
				'message' => $this->l10n->t('The file changed while its pad was being set up. Try again.'),
			], Http::STATUS_CONFLICT);
		} catch (PadFileAlreadyExistsException $e) {
			return $this->answer($e, ['message' => $this->l10n->t('A file with this name already exists.')], Http::STATUS_CONFLICT);
		} catch (PadAlreadyHasBindingException $e) {
			return $this->answer($e, ['message' => $this->l10n->t('This .pad file is already linked to a pad.')], Http::STATUS_CONFLICT);
		} catch (PadParentFolderNotWritableException $e) {
			return $this->answer($e, ['message' => $this->l10n->t('Selected parent folder is not writable.')], Http::STATUS_FORBIDDEN);
		} catch (PadTypeDisabledException $e) {
			$payload = ['message' => $this->l10n->t('This pad type is disabled on this instance.')];
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
				// Not a dead end: once the row is settled, the same request
				// opens the pad - or answers missing_binding.
				$status = Http::STATUS_CONFLICT;
			}
			return $this->answer($e, ['message' => $this->padResponses->bindingErrorMessage($e)], $status);
		} catch (LegacyProtectedImportDisabledException $e) {
			// 403, not 409: nothing conflicts, the instance does not offer
			// this import at all.
			return $this->answer($e, [
				'message' => $this->l10n->t('This file is a legacy Ownpad link to a protected pad, and importing those is disabled on this server. Please contact your administrator.'),
			], Http::STATUS_FORBIDDEN);
		} catch (LegacyPadCollisionException $e) {
			// 409, not 403: the conflict is with the *other* file's binding,
			// not access denied to the file being opened.
			return $this->answer($e, [
				'message' => $this->l10n->t('This pad is already linked to another file you do not have access to.'),
			], Http::STATUS_CONFLICT);
		} catch (EtherpadTooLargeException $e) {
			// The pad is fine and still editable; only this preview refuses
			// to read that much of it.
			return $this->answer($e, [
				'message' => $this->l10n->t('This pad is too large to show here. Open it in Etherpad instead.'),
			], Http::STATUS_BAD_REQUEST);
		} catch (MissingFrontmatterException $e) {
			// The one .pad problem the clients act on rather than report, by
			// its code: they initialise the file and retry.
			return $this->answer($e, ['message' => $this->l10n->t('This .pad file has no pad metadata yet.')], Http::STATUS_BAD_REQUEST);
		} catch (PadFileFormatException $e) {
			return $this->answer($e, ['message' => $this->l10n->t('The selected .pad file has an invalid format.')], Http::STATUS_BAD_REQUEST);
		} catch (ExternalPadException $e) {
			// What was wrong with the link to another server: the user's to
			// mend, not this admin's.
			return $this->answer($e, ['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (EtherpadClientException $e) {
			$this->etherpadFailures->report('Etherpad failed while answering a pad request.', $e, $options['context'] ?? []);
			return $this->answer($e, ['message' => $this->l10n->t('Etherpad could not complete the request. Try again later.')], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error($options['failure'] ?? 'Unhandled pad controller error', [
				'app' => 'etherpad_nextcloud',
				...($options['context'] ?? []),
				...SafeError::context($e),
			]);
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
}
