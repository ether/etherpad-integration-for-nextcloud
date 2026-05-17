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
use OCA\EtherpadNextcloud\Service\PadInitializationResult;
use OCA\EtherpadNextcloud\Service\PadInitializationService;
use OCA\EtherpadNextcloud\Service\PadLifecycleOperationService;
use OCA\EtherpadNextcloud\Service\PadMeta;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadOpenService;
use OCA\EtherpadNextcloud\Service\PadOpenTarget;
use OCA\EtherpadNextcloud\Service\PadOriginalLookup;
use OCA\EtherpadNextcloud\Service\PadResolution;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PadSyncResult;
use OCA\EtherpadNextcloud\Service\PadSyncStatus;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
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
		private IL10N $l10n,
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
				'invalid_argument' => $this->l10n->t('Invalid file path.'),
				'binding_message' => $this->l10n->t('A file with this name already exists.'),
				'binding_status' => Http::STATUS_CONFLICT,
				'generic' => $this->l10n->t('Could not create pad.'),
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
				'invalid_argument' => $this->l10n->t('Invalid pad name.'),
				'not_found' => $this->l10n->t('Cannot resolve selected parent folder.'),
				'binding_message' => $this->l10n->t('A file with this name already exists.'),
				'binding_status' => Http::STATUS_CONFLICT,
				'generic' => $this->l10n->t('Could not create pad.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function createFromTemplate(string $file, int $templateFileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padCreationService->createFromTemplate(
				$user->getUID(),
				$file,
				$this->requireFileId($templateFileId),
				$user,
			),
			fn(array $result): DataResponse => new DataResponse($this->padResponses->withViewerUrl($result)),
			[
				'invalid_argument' => $this->l10n->t('Invalid input.'),
				'not_found' => $this->l10n->t('Template file not found.'),
				'binding_message' => $this->l10n->t('A file with this name already exists.'),
				'binding_status' => Http::STATUS_CONFLICT,
				'generic' => $this->l10n->t('Could not create pad from template.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function createFromUrl(string $file, string $padUrl): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padCreationService->createFromUrl($user->getUID(), $file, $padUrl),
			fn(array $result): DataResponse => new DataResponse($this->padResponses->withViewerUrl($result)),
			[
				'invalid_argument' => $this->l10n->t('Invalid input.'),
				'generic' => $this->l10n->t('Could not import external pad.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function open(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): PadOpenTarget => $this->padOpenService->openByPath($user->getUID(), $user->getDisplayName(), $file),
			fn(PadOpenTarget $result): DataResponse => $this->padResponses->openResponse($result),
			[
				'invalid_argument' => $this->l10n->t('Invalid file path.'),
				'not_found' => $this->l10n->t('Cannot open selected .pad file.'),
				'generic' => $this->l10n->t('Could not open pad.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function openById(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): PadOpenTarget => $this->padOpenService->openById($user->getUID(), $user->getDisplayName(), $this->requireFileId($fileId)),
			fn(PadOpenTarget $result): DataResponse => $this->padResponses->openResponse($result),
			[
				'not_found' => $this->l10n->t('Cannot open selected .pad file.'),
				'generic' => $this->l10n->t('Could not open pad.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function initialize(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): PadInitializationResult => $this->padInitializationService->initializeByPath($user->getUID(), $file),
			fn(PadInitializationResult $result): DataResponse => new DataResponse($this->padResponses->initializationResponse($result)),
			[
				'invalid_argument' => $this->l10n->t('Invalid file path.'),
				'not_found' => $this->l10n->t('Cannot open selected .pad file.'),
				'generic' => $this->l10n->t('Could not initialize pad file.'),
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
			fn(IUser $user): PadInitializationResult => $this->padInitializationService->initializeById($user->getUID(), $this->requireFileId($fileId)),
			fn(PadInitializationResult $result): DataResponse => new DataResponse($this->padResponses->initializationResponse($result)),
			[
				'not_found' => $this->l10n->t('Cannot open selected .pad file.'),
				'generic' => $this->l10n->t('Could not initialize pad file.'),
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
			fn(IUser $user): PadMeta => $this->padMetadataService->metaById($user->getUID(), $this->requireFileId($fileId)),
			fn(PadMeta $meta): DataResponse => new DataResponse($this->padResponses->metaResponse($meta)),
			[
				'not_found' => $this->l10n->t('Cannot resolve selected .pad file.'),
				'generic' => $this->l10n->t('Could not read pad metadata.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function resolveById(int $fileId = 0, string $file = ''): DataResponse {
		return $this->runForUser(
			fn(IUser $user): PadResolution => $this->padMetadataService->resolve($user->getUID(), $fileId, $file),
			fn(PadResolution $resolution): DataResponse => new DataResponse($this->padResponses->resolveResponse($resolution)),
			[
				'invalid_argument' => $this->l10n->t('Invalid file path.'),
				'generic' => $this->l10n->t('Could not resolve pad file.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function syncById(int $fileId): DataResponse {
		$forceParam = (string)$this->request->getParam('force', '0');
		$force = in_array(strtolower($forceParam), ['1', 'true', 'yes'], true);

		return $this->runForUser(
			fn(IUser $user): PadSyncResult => $this->padSyncService->syncById($user->getUID(), $this->requireFileId($fileId), $force),
			fn(PadSyncResult $result): DataResponse => new DataResponse($this->padResponses->syncResponse($result)),
			[
				'not_found' => $this->l10n->t('Cannot resolve file path for file ID.'),
				'generic' => $this->l10n->t('Could not sync pad content.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function syncStatusById(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): PadSyncStatus => $this->padSyncService->syncStatusById($user->getUID(), $this->requireFileId($fileId)),
			fn(PadSyncStatus $result): DataResponse => new DataResponse($this->padResponses->syncStatusResponse($result)),
			[
				'not_found' => $this->l10n->t('Cannot read selected .pad file.'),
				'generic' => $this->l10n->t('Could not check pad sync status.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function trash(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padLifecycleOperations->trashByPath($user->getUID(), $file),
			fn(array $result): DataResponse => $this->padResponses->lifecycleResponse($result),
			[
				'invalid_argument' => $this->l10n->t('Invalid file path.'),
				'not_found' => $this->l10n->t('Pad file not found.'),
				'generic' => $this->l10n->t('Could not move pad to trash.'),
				'on_throwable' => fn(\Throwable $e) => $this->logError('Pad trash API failed', [
					'file' => $file,
					'exception' => $e,
				]),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function findOriginalByFileId(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): PadOriginalLookup => $this->padMetadataService->findOriginalForCopy(
				$user->getUID(),
				$this->requireFileId($fileId),
			),
			fn(PadOriginalLookup $lookup): DataResponse => new DataResponse($this->padResponses->originalLookupResponse($lookup)),
			[
				'generic' => $this->l10n->t('Could not look up the original pad.'),
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function recoverByFileId(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padLifecycleOperations->recoverByFileId($user->getUID(), $this->requireFileId($fileId)),
			fn(array $result): DataResponse => $this->padResponses->lifecycleResponse($result),
			[
				'not_found' => $this->l10n->t('Pad file not found.'),
				'generic' => $this->l10n->t('Could not recover pad from this file.'),
				'on_throwable' => fn(\Throwable $e) => $this->logError('Pad recovery API failed', [
					'fileId' => $fileId,
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
				'invalid_argument' => $this->l10n->t('Invalid file path.'),
				'not_found' => $this->l10n->t('Pad file not found.'),
				'generic' => $this->l10n->t('Could not restore pad from trash.'),
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
