<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Service\LivePadHtml;
use OCA\EtherpadNextcloud\Service\PadContentService;
use OCA\EtherpadNextcloud\Service\PadInitializationResult;
use OCA\EtherpadNextcloud\Service\PadInitializationService;
use OCA\EtherpadNextcloud\Service\PadMeta;
use OCA\EtherpadNextcloud\Service\PadMetadataService;
use OCA\EtherpadNextcloud\Service\PadOpenService;
use OCA\EtherpadNextcloud\Service\PadOpenTarget;
use OCA\EtherpadNextcloud\Service\PadResolution;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Open / initialize / meta endpoints — anything that materializes the
 * pad session state for a viewer: actual open flow, lazy frontmatter
 * init on first open, and the metadata + resolve readouts used by
 * embed-side surfaces.
 * @psalm-api
 */
class PadSessionController extends AbstractPadController {
	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		IL10N $l10n,
		PadResponseService $padResponses,
		PadControllerErrorMapper $errors,
		private PadOpenService $padOpenService,
		private PadInitializationService $padInitializationService,
		private PadMetadataService $padMetadataService,
		private PadContentService $padContentService,
	) {
		parent::__construct($appName, $request, $userSession, $logger, $l10n, $padResponses, $errors);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function open(string $file): DataResponse {
		return $this->runForUser(
			fn(IUser $user): PadOpenTarget => $this->padOpenService->openByPath($user->getUID(), $user->getDisplayName(), $file),
			fn(PadOpenTarget $result): DataResponse => $this->padResponses->openResponse($result),
			[
				'invalid_argument' => $this->l10n->t('Invalid file path.'),
				'not_found' => $this->l10n->t('Cannot open selected .pad file.'),
				'generic' => $this->l10n->t('Could not open pad'),
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
				'generic' => $this->l10n->t('Could not open pad'),
			],
		);
	}

	/**
	 * The current pad content for this app's read-only view.
	 *
	 * Its own endpoint rather than a field on the open: the viewer retries
	 * with it, and every retry has to pass the access checks again.
	 */
	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function contentById(int $fileId): DataResponse {
		return $this->runForUser(
			fn(IUser $user): LivePadHtml => $this->padContentService->contentById($user->getUID(), $this->requireFileId($fileId)),
			fn(LivePadHtml $content): DataResponse => $this->padResponses->padContentResponse($content),
			[
				'not_found' => $this->l10n->t('Cannot open selected .pad file.'),
				'too_large' => $this->l10n->t('This pad is too large to show here. Open it in Etherpad instead.'),
				'generic' => $this->l10n->t('Could not load the pad content.'),
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
				'generic' => $this->l10n->t('Could not initialize .pad file.'),
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
				'generic' => $this->l10n->t('Could not initialize .pad file.'),
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
				'generic' => $this->l10n->t('Could not resolve .pad file.'),
			],
		);
	}
}
