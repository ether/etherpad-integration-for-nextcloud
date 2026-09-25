<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Create-side endpoints for `.pad` files. Spans empty creates, copies in
 * a parent folder, template-based creates, and external-URL imports.
 * @psalm-api
 */
class PadCreateController extends AbstractPadController {
	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		IL10N $l10n,
		PadResponseService $padResponses,
		PadControllerErrorMapper $errors,
		private PadCreationService $padCreationService,
	) {
		parent::__construct($appName, $request, $userSession, $l10n, $padResponses, $errors);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function create(string $file, string $accessMode = BindingService::ACCESS_PROTECTED): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padCreationService->create($user->getUID(), $file, $this->requireAccessMode($accessMode)),
			fn(array $result): DataResponse => new DataResponse($this->padResponses->withViewerUrl($result)),
			[
				'binding_message' => $this->l10n->t('A file with this name already exists.'),
				'binding_status' => Http::STATUS_CONFLICT,
				'generic' => $this->l10n->t('Could not create pad'),
				'failure' => 'Pad creation failed',
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
				'generic' => $this->l10n->t('Could not create pad'),
				'failure' => 'Pad creation by parent failed',
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
				// Not only a path: the template itself can be refused.
				'invalid_argument' => $this->l10n->t('Invalid input.'),
				'not_found' => $this->l10n->t('Template file not found.'),
				'binding_message' => $this->l10n->t('A file with this name already exists.'),
				'binding_status' => Http::STATUS_CONFLICT,
				'generic' => $this->l10n->t('Could not create pad from template.'),
				'failure' => 'Pad create-from-template failed',
			],
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	public function createFromUrl(string $file, string $padUrl): DataResponse {
		return $this->runForUser(
			fn(IUser $user): array => $this->padCreationService->createFromUrl($user->getUID(), $file, $padUrl),
			fn(array $result): DataResponse => new DataResponse($this->padResponses->withViewerUrl($result)),
			[
				'generic' => $this->l10n->t('Could not import external pad.'),
				'failure' => 'External pad create failed',
			],
		);
	}
}
