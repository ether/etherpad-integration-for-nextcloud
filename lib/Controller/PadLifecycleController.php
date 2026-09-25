<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Service\LifecycleService;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadOriginalLookup;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCA\EtherpadNextcloud\Service\PadSyncResult;
use OCA\EtherpadNextcloud\Service\PadSyncService;
use OCA\EtherpadNextcloud\Service\PadSyncStatus;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Lifecycle + sync endpoints — trash, restore, recover-from-snapshot,
 * sync state, and the find-original lookup that the copy-of-a-pad
 * recovery affordance hangs off.
 * @psalm-api
 */
class PadLifecycleController extends AbstractPadController {
	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		IL10N $l10n,
		PadResponseService $padResponses,
		PadControllerErrorMapper $errors,
		private LifecycleService $lifecycleService,
		private PadSyncService $padSyncService,
		private PadMetadataService $padMetadataService,
	) {
		parent::__construct($appName, $request, $userSession, $l10n, $padResponses, $errors);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function trash(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->lifecycleService->trashByPath($user->getUID(), $file),
			fn(array $result): DataResponse => $this->padResponses->lifecycleResponse($result),
			[
				'invalid_argument' => $this->l10n->t('Invalid file path.'),
				'generic' => $this->l10n->t('Could not move pad to trash.'),
				'failure' => 'Pad trash API failed',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function restore(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->lifecycleService->restoreByPath($user->getUID(), $file),
			fn(array $result): DataResponse => $this->padResponses->lifecycleResponse($result),
			[
				'invalid_argument' => $this->l10n->t('Invalid file path.'),
				'generic' => $this->l10n->t('Could not restore pad from trash.'),
				'failure' => 'Pad restore API failed',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function recoverByFileId(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->lifecycleService->recoverByFileId($user->getUID(), $this->requireFileId($fileId)),
			fn(array $result): DataResponse => $this->padResponses->lifecycleResponse($result),
			[
				'generic' => $this->l10n->t('Could not recover pad from this file.'),
				'failure' => 'Pad recovery API failed',
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
				'generic' => $this->l10n->t('Could not check pad sync status.'),
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
}
