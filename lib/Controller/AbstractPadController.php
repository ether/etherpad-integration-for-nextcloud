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

/**
 * Shared infrastructure for the three pad-API controllers:
 * `PadCreateController`, `PadSessionController`, `PadLifecycleController`.
 *
 * Holds the cross-cutting deps (user session, l10n, response builder,
 * error mapper) and the small set of helpers every action in
 * those controllers reaches for (`runForUser`, which also names the
 * request's file for the error mapper's log lines, and the parameter
 * guards).
 *
 * Each concrete controller keeps its constructor narrow — it only
 * declares the services it actually uses on top of the base deps.
 *
 * @psalm-import-type ErrorWording from PadControllerErrorMapper
 */
abstract class AbstractPadController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		protected IUserSession $userSession,
		protected IL10N $l10n,
		protected PadResponseService $padResponses,
		protected PadControllerErrorMapper $errors,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param callable(IUser): mixed $action
	 * @param callable(mixed): DataResponse $success
	 * @param ErrorWording $options
	 */
	protected function runForUser(callable $action, callable $success, array $options = []): DataResponse {
		return $this->errors->run(
			fn(): mixed => $action($this->requireUser()),
			$success,
			$this->withTheRequestsFile($options),
		);
	}

	/**
	 * @param ErrorWording $options
	 * @return ErrorWording
	 */
	private function withTheRequestsFile(array $options): array {
		$options['context'] = $this->fileOfTheRequest();
		return $options;
	}

	/**
	 * What the log line of a request that failed names: its file, as the
	 * request gave it - by id, by path, or both.
	 *
	 * @return array<string, int|string>
	 */
	private function fileOfTheRequest(): array {
		$context = [];
		$fileId = self::scalar($this->request->getParam('fileId'));
		if (ctype_digit($fileId)) {
			$context['fileId'] = (int)$fileId;
		}
		$file = self::scalar($this->request->getParam('file'));
		if ($file !== '') {
			$context['file'] = $file;
		}
		return $context;
	}

	private static function scalar(mixed $param): string {
		return is_scalar($param) ? (string)$param : '';
	}

	protected function requireUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new UnauthorizedRequestException('Authentication required.');
		}
		return $user;
	}

	protected function requireFileId(int $fileId): int {
		return $this->requirePositiveInt($fileId, $this->l10n->t('Invalid file ID.'));
	}

	protected function requireParentFolderId(int $parentFolderId): int {
		return $this->requirePositiveInt($parentFolderId, $this->l10n->t('Invalid parentFolderId.'));
	}

	/** $message is translated: it reaches the client as it is. */
	protected function requirePositiveInt(int $value, string $message): int {
		if ($value <= 0) {
			throw new ControllerBadRequestException($message);
		}
		return $value;
	}

	protected function requireAccessMode(string $accessMode): string {
		if (PadAccessMode::tryFrom($accessMode) === null) {
			throw new ControllerBadRequestException($this->l10n->t('Invalid accessMode. Use public or protected.'));
		}
		return $accessMode;
	}

}
