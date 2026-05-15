<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 *
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\ControllerBadRequestException;
use OCA\EtherpadNextcloud\Exception\UnauthorizedRequestException;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;

class ViewerController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private IL10N $l10n,
		private PathNormalizer $pathNormalizer,
		private UserNodeResolver $userNodeResolver,
		private ViewerControllerErrorMapper $errors,
	) {
		parent::__construct($appName, $request);
	}

	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function showPad(mixed $file = ''): TemplateResponse|RedirectResponse {
		return $this->errors->runForTemplate(
			function () use ($file): array|RedirectResponse {
				$user = $this->requireUser();
				$normalizedFile = $this->normalizeOrThrow($file);
				if ($normalizedFile === '') {
					return new RedirectResponse($this->urlGenerator->linkToRoute('files.view.index'));
				}
				$fileNode = $this->userNodeResolver->resolveUserFileNodeByPath($user->getUID(), $normalizedFile);
				return [
					'file_id' => (int)$fileNode->getId(),
					'path' => $normalizedFile,
				];
			},
			fn(array|RedirectResponse $result): RedirectResponse => $result instanceof RedirectResponse
				? $result
				: new RedirectResponse($this->buildFilesOpenUrl($result['file_id'], $result['path'])),
		);
	}

	#[\OCP\AppFramework\Http\Attribute\PublicPage]
	#[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
	public function showPadById(mixed $fileId): TemplateResponse|RedirectResponse {
		return $this->errors->runForTemplate(
			function () use ($fileId): array {
				$user = $this->requireUser();
				$id = $this->requireFileId($fileId);
				$fileNode = $this->userNodeResolver->resolveUserFileNodeById($user->getUID(), $id);
				$path = $this->userNodeResolver->toUserAbsolutePath($user->getUID(), $fileNode);
				return ['file_id' => $id, 'path' => $path];
			},
			fn(array $resolved): RedirectResponse => new RedirectResponse(
				$this->buildFilesOpenUrl($resolved['file_id'], $resolved['path'])
			),
			notFoundMessage: $this->l10n->t('Cannot resolve file path for file ID.'),
		);
	}

	private function requireUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new UnauthorizedRequestException();
		}
		return $user;
	}

	private function requireFileId(mixed $candidate): int {
		if (!is_numeric($candidate)) {
			throw new ControllerBadRequestException($this->l10n->t('Invalid file ID.'));
		}
		$id = (int)$candidate;
		if ($id <= 0) {
			throw new ControllerBadRequestException($this->l10n->t('Invalid file ID.'));
		}
		return $id;
	}

	private function normalizeOrThrow(mixed $file): string {
		try {
			return $this->pathNormalizer->normalizeViewerFilePath($file);
		} catch (\Throwable) {
			throw new ControllerBadRequestException($this->l10n->t('Invalid file path.'));
		}
	}

	private function buildFilesOpenUrl(int $fileId, string $absoluteFilePath): string {
		$dir = dirname($absoluteFilePath);
		if ($dir === '.' || $dir === '') {
			$dir = '/';
		}
		// `files.view.index` resolves to '/apps/files'; the canonical URL
		// the Files app routes to a specific file is
		// `/apps/files/{view}/{fileid}` with `files` as the default view.
		$base = rtrim($this->urlGenerator->linkToRoute('files.view.index'), '/');
		return $base . '/files/' . rawurlencode((string)$fileId)
			. '?dir=' . rawurlencode($dir)
			. '&editing=false&openfile=true';
	}
}
