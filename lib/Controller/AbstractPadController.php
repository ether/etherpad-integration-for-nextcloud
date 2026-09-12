<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Util\PadAccessMode;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Shared infrastructure for the three pad-API controllers:
 * `PadCreateController`, `PadSessionController`, `PadLifecycleController`.
 *
 * Holds the cross-cutting deps (user session, logger, l10n, response
 * builder, error mapper) and the small set of helpers every action in
 * those controllers reaches for (`runForUser`, parameter guards,
 * structured error logging).
 *
 * Each concrete controller keeps its constructor narrow — it only
 * declares the services it actually uses on top of the base deps.
 * @psalm-api
 */
abstract class AbstractPadController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		protected IUserSession $userSession,
		protected LoggerInterface $logger,
		protected IL10N $l10n,
		protected PadResponseService $padResponses,
		protected PadControllerErrorMapper $errors,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param callable(IUser): mixed $action
	 * @param callable(mixed): DataResponse $success
	 * @param array<string,mixed> $options
	 */
	protected function runForUser(callable $action, callable $success, array $options = []): DataResponse {
		return $this->errors->run(
			fn(): mixed => $action($this->requireUser()),
			$success,
			$options,
		);
	}

	protected function requireUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new UnauthorizedRequestException('Authentication required.');
		}
		return $user;
	}

	protected function requireFileId(int $fileId): int {
		return $this->requirePositiveInt($fileId, 'Invalid file ID.');
	}

	protected function requireParentFolderId(int $parentFolderId): int {
		return $this->requirePositiveInt($parentFolderId, 'Invalid parentFolderId.');
	}

	protected function requirePositiveInt(int $value, string $message): int {
		if ($value <= 0) {
			throw new ControllerBadRequestException($message);
		}
		return $value;
	}

	protected function requireAccessMode(string $accessMode): string {
		if (PadAccessMode::tryFrom($accessMode) === null) {
			throw new ControllerBadRequestException('Invalid accessMode. Use public or protected.');
		}
		return $accessMode;
	}

	/** @param array<string,mixed> $context */
	protected function logError(string $message, array $context): void {
		$this->logger->error($message, ['app' => 'etherpad_nextcloud'] + $context);
	}
}
