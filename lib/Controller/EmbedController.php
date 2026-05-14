<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadParentFolderNotWritableException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\EmbedResponseBuilder;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;

class EmbedController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private EmbedResponseBuilder $responseBuilder,
		private UserNodeResolver $userNodeResolver,
		private EmbedControllerErrorMapper $errors,
	) {
		parent::__construct($appName, $request);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function showById(mixed $fileId): TemplateResponse {
		return $this->errors->runForTemplate(
			function () use ($fileId): array {
				$user = $this->requireUser();
				$id = $this->requireNumericFileId($fileId);
				$fileNode = $this->userNodeResolver->resolveUserFileNodeById($user->getUID(), $id);
				if (!str_ends_with(strtolower($fileNode->getName()), '.pad')) {
					throw new NotAPadFileException($this->l10n->t('Selected file is not a .pad file.'));
				}
				return ['file_id' => $id];
			},
			fn(array $resolved): TemplateResponse => $this->responseBuilder->build('embed', [
				'file_id' => $resolved['file_id'],
				'open_by_id_url' => $this->urlGenerator->linkToRoute($this->appName . '.pad.openById'),
				'initialize_by_id_url_template' => $this->urlGenerator->linkToRoute(
					$this->appName . '.pad.initializeById',
					['fileId' => '__FILE_ID__']
				),
				'l10n' => [
					'loading' => $this->l10n->t('Loading pad...'),
					'error_title' => $this->l10n->t('Unable to open pad'),
					'external_title' => $this->l10n->t('Pad from another server'),
					'external_message' => $this->l10n->t('Read-only snapshot from the .pad file.'),
					'external_empty' => $this->l10n->t('No synced snapshot is stored in this .pad file yet.'),
					'external_link' => $this->l10n->t('Open original pad'),
				],
			]),
			errorTitle: $this->l10n->t('Unable to open pad'),
		);
	}

	#[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function createByParent(mixed $parentFolderId): TemplateResponse {
		return $this->errors->runForTemplate(
			function () use ($parentFolderId): array {
				$user = $this->requireUser();
				$id = $this->requirePositiveInt($parentFolderId, $this->l10n->t('Invalid parent folder ID.'));
				$parentFolder = $this->userNodeResolver->resolveUserFolderNodeById($user->getUID(), $id);
				if (!$parentFolder->isCreatable()) {
					throw new PadParentFolderNotWritableException();
				}
				return ['parent_folder_id' => $id];
			},
			fn(array $resolved): TemplateResponse => $this->responseBuilder->build('embed-create', [
				'parent_folder_id' => $resolved['parent_folder_id'],
				'create_by_parent_url' => $this->urlGenerator->linkToRoute($this->appName . '.pad.createByParent'),
				'l10n' => [
					'loading' => $this->l10n->t('Creating pad...'),
					'error_title' => $this->l10n->t('Unable to create pad'),
					'missing_name' => $this->l10n->t('Pad name is required.'),
					'invalid_access_mode' => $this->l10n->t('Invalid access mode.'),
					'incomplete_config' => $this->l10n->t('Embed configuration is incomplete.'),
				],
			]),
			errorTitle: $this->l10n->t('Unable to create pad'),
		);
	}

	private function requireUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new UnauthorizedRequestException();
		}
		return $user;
	}

	private function requireNumericFileId(mixed $candidate): int {
		return $this->requirePositiveInt($candidate, $this->l10n->t('Invalid file ID.'));
	}

	private function requirePositiveInt(mixed $candidate, string $message): int {
		if (!is_numeric($candidate)) {
			throw new ControllerBadRequestException($message);
		}
		$id = (int)$candidate;
		if ($id <= 0) {
			throw new ControllerBadRequestException($message);
		}
		return $id;
	}
}
