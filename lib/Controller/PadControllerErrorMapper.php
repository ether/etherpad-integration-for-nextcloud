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
use OCA\EtherpadNextcloud\Exception\PadFileAlreadyExistsException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Centralizes mapping domain and framework exceptions to HTTP DataResponses.
 * Endpoints only provide wording per category; RuntimeException messages are
 * intentionally not exposed to clients to avoid leaking internal details.
 */
class PadControllerErrorMapper {
	public function __construct(
		private PadResponseService $padResponses,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): DataResponse $success
	 * @param array{
	 *   invalid_argument?: string,
	 *   not_found?: string,
	 *   binding_message?: string,
	 *   binding_status?: int,
	 *   generic?: string,
	 *   map_throwable?: callable(\Throwable): ?DataResponse,
	 *   on_throwable?: callable(\Throwable): void
	 * } $options
	 */
	public function run(callable $action, callable $success, array $options = []): DataResponse {
		try {
			return $success($action());
		} catch (UnauthorizedRequestException $e) {
			return new DataResponse([
				'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Authentication required.',
			], Http::STATUS_UNAUTHORIZED);
		} catch (ControllerBadRequestException $e) {
			return new DataResponse([
				'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Invalid input.',
			], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			$configuredMessage = isset($options['invalid_argument'])
				? (string)$options['invalid_argument']
				: '';
			$exceptionMessage = trim($e->getMessage());
			$message = $configuredMessage !== ''
				? $configuredMessage
				: ($exceptionMessage !== '' ? $exceptionMessage : 'Invalid input.');

			return new DataResponse([
				'message' => $message,
			], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException) {
			return new DataResponse([
				'message' => (string)($options['not_found'] ?? 'Resource not found.'),
			], Http::STATUS_NOT_FOUND);
		} catch (LockedException) {
			return new DataResponse([
				'message' => 'Pad file is temporarily locked. Please retry.',
				'retryable' => true,
			], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (PadFileAlreadyExistsException) {
			return new DataResponse([
				'message' => 'A file with this name already exists.',
			], Http::STATUS_CONFLICT);
		} catch (PadParentFolderNotWritableException) {
			return new DataResponse([
				'message' => 'Selected parent folder is not writable.',
			], Http::STATUS_FORBIDDEN);
		} catch (BindingException $e) {
			$message = isset($options['binding_message'])
				? (string)$options['binding_message']
				: $this->padResponses->bindingErrorMessage($e);
			return new DataResponse([
				'message' => $message,
			], (int)($options['binding_status'] ?? Http::STATUS_BAD_REQUEST));
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			$mapped = $this->mapThrowable($e, $options);
			if ($mapped instanceof DataResponse) {
				return $mapped;
			}
			return $this->genericResponse($e, $options);
		} catch (\Throwable $e) {
			$mapped = $this->mapThrowable($e, $options);
			if ($mapped instanceof DataResponse) {
				return $mapped;
			}
			return $this->genericResponse($e, $options);
		}
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

	/** @param array<string,mixed> $options */
	private function genericResponse(\Throwable $e, array $options): DataResponse {
		$logger = $options['on_throwable'] ?? null;
		if (is_callable($logger)) {
			$logger($e);
		} else {
			$this->logger->error('Unhandled pad controller error', [
				'app' => 'etherpad_nextcloud',
				'exception' => $e,
			]);
		}
		return new DataResponse([
			'message' => (string)($options['generic'] ?? 'Request failed.'),
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
