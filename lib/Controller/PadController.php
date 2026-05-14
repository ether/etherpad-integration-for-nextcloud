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
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadInitializationService;
use OCA\EtherpadNextcloud\Service\PadLifecycleOperationService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadOpenService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class PadController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private PadCreationService $padCreationService,
		private PadInitializationService $padInitializationService,
		private PadMetadataService $padMetadataService,
		private PadOpenService $padOpenService,
		private PadSyncService $padSyncService,
		private PadLifecycleOperationService $padLifecycleOperations,
		private PadResponseService $padResponses,
		private PadControllerErrorMapper $errors,
	) {
		parent::__construct($appName, $request);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function create(string $file, string $accessMode = BindingService::ACCESS_PROTECTED): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padCreationService->create($user->getUID(), $file, $this->requireAccessMode($accessMode)),
			fn(array $result): DataResponse => new DataResponse($this->padResponses->withViewerUrl($result)),
			[
				'invalid_argument' => 'Invalid file path.',
				'binding_message' => 'A file with this name already exists.',
				'binding_status' => Http::STATUS_CONFLICT,
				'generic' => 'Pad creation failed.',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function createByParent(int $parentFolderId, string $name, string $accessMode = BindingService::ACCESS_PROTECTED): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padCreationService->createInParent(
				$user->getUID(),
				$this->requireParentFolderId($parentFolderId),
				$name,
				$this->requireAccessMode($accessMode),
			),
			fn(array $result): DataResponse => new DataResponse($this->padResponses->withViewerAndEmbedUrls($result)),
			[
				'invalid_argument' => 'Invalid pad name.',
				'not_found' => 'Cannot resolve selected parent folder.',
				'binding_message' => 'A file with this name already exists.',
				'binding_status' => Http::STATUS_CONFLICT,
				'generic' => 'Pad creation failed.',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function createFromUrl(string $file, string $padUrl): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padCreationService->createFromUrl($user->getUID(), $file, $padUrl),
			fn(array $result): DataResponse => new DataResponse($this->padResponses->withViewerUrl($result)),
			[
				'invalid_argument' => 'Invalid input.',
				'generic' => 'External pad create failed.',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function open(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padOpenService->openByPath($user->getUID(), $user->getDisplayName(), $file),
			fn(array $result): DataResponse => $this->padResponses->openResponse($result),
			[
				'invalid_argument' => 'Invalid file path.',
				'not_found' => 'Cannot open selected .pad file.',
				'generic' => 'Pad open failed.',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function openById(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padOpenService->openById($user->getUID(), $user->getDisplayName(), $this->requireFileId($fileId)),
			fn(array $result): DataResponse => $this->padResponses->openResponse($result),
			[
				'not_found' => 'Cannot open selected .pad file.',
				'generic' => 'Pad open failed.',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function initialize(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padInitializationService->initializeByPath($user->getUID(), $file),
			fn(array $result): DataResponse => new DataResponse($result),
			[
				'invalid_argument' => 'Invalid file path.',
				'not_found' => 'Cannot open selected .pad file.',
				'generic' => 'Pad initialization failed.',
				'on_throwable' => fn(\Throwable $e) => $this->logError('Pad frontmatter initialization failed in API initialize', [
					'file' => $file,
					'exception' => $e,
				]),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function initializeById(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padInitializationService->initializeById($user->getUID(), $this->requireFileId($fileId)),
			fn(array $result): DataResponse => new DataResponse($result),
			[
				'not_found' => 'Cannot open selected .pad file.',
				'generic' => 'Pad initialization failed.',
				'on_throwable' => fn(\Throwable $e) => $this->logError('Pad frontmatter initialization failed in API initialize-by-id', [
					'fileId' => $fileId,
					'exception' => $e,
				]),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function metaById(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padMetadataService->metaById($user->getUID(), $this->requireFileId($fileId)),
			fn(array $data): DataResponse => new DataResponse(
				($data['is_pad'] ?? false) === true
					? $this->padResponses->withViewerAndEmbedUrls($data)
					: $data
			),
			[
				'not_found' => 'Cannot resolve selected .pad file.',
				'generic' => 'Pad meta failed.',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function resolveById(int $fileId = 0, string $file = ''): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padMetadataService->resolve($user->getUID(), $fileId, $file),
			fn(array $data): DataResponse => new DataResponse(
				($data['is_pad'] ?? false) === true
					? $this->padResponses->withViewerUrl($data)
					: $data
			),
			[
				'invalid_argument' => 'Invalid file path.',
				'generic' => 'Pad resolve failed.',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function syncById(int $fileId): DataResponse {
		$forceParam = (string)$this->request->getParam('force', '0');
		$force = in_array(strtolower($forceParam), ['1', 'true', 'yes'], true);

		return $this->runForUser(
			fn(IUser $user): array => $this->padSyncService->syncById($user->getUID(), $this->requireFileId($fileId), $force),
			fn(array $result): DataResponse => new DataResponse($result),
			[
				'not_found' => 'Cannot resolve file path for file ID.',
				'generic' => 'Pad sync failed.',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function syncStatusById(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padSyncService->syncStatusById($user->getUID(), $this->requireFileId($fileId)),
			fn(array $result): DataResponse => new DataResponse($result),
			[
				'not_found' => 'Cannot read selected .pad file.',
				'generic' => 'Sync status check failed.',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function trash(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padLifecycleOperations->trashByPath($user->getUID(), $file),
			fn(array $result): DataResponse => $this->padResponses->lifecycleResponse($result),
			[
				'invalid_argument' => 'Invalid file path.',
				'not_found' => 'Pad file not found.',
				'generic' => 'Trash failed.',
				'on_throwable' => fn(\Throwable $e) => $this->logError('Pad trash API failed', [
					'file' => $file,
					'exception' => $e,
				]),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function restore(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padLifecycleOperations->restoreByPath($user->getUID(), $file),
			fn(array $result): DataResponse => $this->padResponses->lifecycleResponse($result),
			[
				'invalid_argument' => 'Invalid file path.',
				'not_found' => 'Pad file not found.',
				'generic' => 'Restore failed.',
				'on_throwable' => fn(\Throwable $e) => $this->logError('Pad restore API failed', [
					'file' => $file,
					'exception' => $e,
				]),
			],
		);
	}

	/**
	 * @param callable(IUser): mixed $action
	 * @param callable(mixed): DataResponse $success
	 * @param array<string,mixed> $options
	 */
	private function runForUser(callable $action, callable $success, array $options = []): DataResponse {
		return $this->errors->run(
			fn(): mixed => $action($this->requireUser()),
			$success,
			$options,
		);
	}

	private function requireUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new UnauthorizedRequestException('Authentication required.');
		}
		return $user;
	}

	private function requireFileId(int $fileId): int {
		return $this->requirePositiveInt($fileId, 'Invalid file ID.');
	}

	private function requireParentFolderId(int $parentFolderId): int {
		return $this->requirePositiveInt($parentFolderId, 'Invalid parentFolderId.');
	}

	private function requirePositiveInt(int $value, string $message): int {
		if ($value <= 0) {
			throw new ControllerBadRequestException($message);
		}
		return $value;
	}

	private function requireAccessMode(string $accessMode): string {
		if (!in_array($accessMode, [BindingService::ACCESS_PUBLIC, BindingService::ACCESS_PROTECTED], true)) {
			throw new ControllerBadRequestException('Invalid accessMode. Use public or protected.');
		}
		return $accessMode;
	}

	/** @param array<string,mixed> $context */
	private function logError(string $message, array $context): void {
		$this->logger->error($message, ['app' => 'etherpad_nextcloud'] + $context);
	}
}
